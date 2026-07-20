<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OAuthTokenModel;
use App\Models\UserModel;
use CodeIgniter\Encryption\EncrypterInterface;
use CodeIgniter\I18n\Time;
use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Client\Provider\GoogleUser;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Token\AccessTokenInterface;
use RuntimeException;

/**
 * Google OAuth2 인증·토큰 관리.
 *
 * 인가 URL 생성, code→token 교환, refresh token 을 이용한 갱신, 그리고
 * refresh/access token 의 암호화 저장·복호화를 담당한다.
 * refresh/access token 원문은 이 클래스 밖으로 노출하지 않는다(응답·로그 금지).
 */
final class GooglePhotosAuthService
{
    public function __construct(
        private readonly Google $provider,
        private readonly OAuthTokenModel $tokenModel,
        private readonly UserModel $userModel,
        private readonly EncrypterInterface $encrypter,
        private readonly int $expiryMarginSeconds = 60,
    ) {
    }

    /**
     * 콜백 처리: code 교환 → 사용자 식별(sub·email·name) → user upsert → 토큰 암호화 저장.
     *
     * @return int 내부 user_id
     */
    public function handleCallback(string $code): int
    {
        $token = $this->exchangeCode($code);

        // getResourceOwner() 는 구체 AccessToken 을 요구한다(인터페이스만으론 부족).
        if (! $token instanceof AccessToken) {
            throw new RuntimeException('예상치 못한 토큰 타입입니다.');
        }

        /** @var GoogleUser $owner */
        $owner = $this->provider->getResourceOwner($token);

        $userId = $this->userModel->upsertByGoogleSub(
            (string) $owner->getId(),
            $owner->getEmail(),
            $owner->getName(),
        );

        $this->storeTokens($userId, $token);

        return $userId;
    }

    /**
     * 인가 URL 을 생성한다.
     *
     * refresh token 확보를 위해 access_type=offline + prompt=consent 를 강제하고,
     * CSRF 방지를 위해 콜백에서 대조할 state 를 포함한다.
     */
    public function getAuthorizationUrl(string $state): string
    {
        return $this->provider->getAuthorizationUrl([
            'state' => $state,
            'access_type' => 'offline',
            'prompt' => 'consent',
        ]);
    }

    /**
     * authorization code 를 access/refresh token 으로 교환한다.
     */
    public function exchangeCode(string $code): AccessTokenInterface
    {
        return $this->provider->getAccessToken('authorization_code', ['code' => $code]);
    }

    /**
     * access/refresh token 을 암호화해 user_id 기준으로 upsert 한다.
     *
     * refresh token 이 응답에 없으면(갱신 응답 등) 기존 값을 보존한다.
     */
    public function storeTokens(int $userId, AccessTokenInterface $token): void
    {
        $data = [
            'access_token_encrypted' => $this->encrypt($token->getToken()),
            'expires_at' => $this->expiresAtString($token),
        ];

        $refreshToken = $token->getRefreshToken();
        if ($refreshToken !== null && $refreshToken !== '') {
            $data['refresh_token_encrypted'] = $this->encrypt($refreshToken);
        }

        $this->tokenModel->upsertByUserId($userId, $data);
    }

    /**
     * 유효한 access token 을 반환한다. 만료(임박) 시 refresh 로 갱신 후 반환한다.
     */
    public function getValidAccessToken(int $userId): string
    {
        $row = $this->tokenModel->findByUserId($userId);
        if ($row === null) {
            throw new RuntimeException('저장된 토큰이 없습니다: user_id=' . $userId);
        }

        $accessEncrypted = $row['access_token_encrypted'] ?? null;
        if ($accessEncrypted === null || $accessEncrypted === '' || $this->isExpired($row['expires_at'] ?? null)) {
            return $this->refreshAccessToken($userId);
        }

        return $this->decrypt((string) $accessEncrypted);
    }

    /**
     * refresh token 으로 access token 을 갱신하고 재저장한 뒤 새 access token 을 반환한다.
     */
    public function refreshAccessToken(int $userId): string
    {
        $row = $this->tokenModel->findByUserId($userId);
        if ($row === null) {
            throw new RuntimeException('저장된 토큰이 없습니다: user_id=' . $userId);
        }

        $refreshToken = $this->decrypt((string) $row['refresh_token_encrypted']);

        $newToken = $this->provider->getAccessToken('refresh_token', [
            'refresh_token' => $refreshToken,
        ]);

        $this->storeTokens($userId, $newToken);

        return $newToken->getToken();
    }

    /**
     * 만료 시각 문자열이 안전 마진을 고려해 이미 지났는지 판정한다.
     */
    private function isExpired(?string $expiresAt): bool
    {
        if ($expiresAt === null || $expiresAt === '') {
            return true;
        }

        return strtotime($expiresAt) <= (Time::now()->getTimestamp() + $this->expiryMarginSeconds);
    }

    private function expiresAtString(AccessTokenInterface $token): ?string
    {
        $expires = $token->getExpires();

        return $expires === null ? null : date('Y-m-d H:i:s', $expires);
    }

    private function encrypt(string $plain): string
    {
        return base64_encode($this->encrypter->encrypt($plain));
    }

    private function decrypt(string $stored): string
    {
        $binary = base64_decode($stored, true);
        if ($binary === false) {
            throw new RuntimeException('암호문 디코딩 실패');
        }

        return $this->encrypter->decrypt($binary);
    }
}
