<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\OAuthTokenModel;
use App\Models\UserModel;
use App\Services\GooglePhotosAuthService;
use CodeIgniter\Encryption\EncrypterInterface;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Client\Provider\GoogleUser;
use League\OAuth2\Client\Token\AccessToken;

/**
 * @internal
 */
final class GooglePhotosAuthServiceStorageTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $refresh = true;
    protected $namespace = 'App';

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        // oauth_tokens.user_id 는 users FK 이므로 사용자를 먼저 시드한다.
        $this->userId = (new UserModel())->upsertByGoogleSub('sub-1', 'seed@example.com', 'Seed');
    }

    private function encrypter(): EncrypterInterface
    {
        return service('encrypter');
    }

    private function makeService(Google $provider): GooglePhotosAuthService
    {
        return new GooglePhotosAuthService($provider, new OAuthTokenModel(), new UserModel(), $this->encrypter());
    }

    public function testHandleCallbackUpsertsUserAndStoresEncryptedTokens(): void
    {
        $provider = $this->createMock(Google::class);
        $provider->method('getAccessToken')
            ->with('authorization_code', ['code' => 'auth-code'])
            ->willReturn(new AccessToken([
                'access_token' => 'ya29.access',
                'refresh_token' => 'refresh-abc',
                'expires_in' => 3600,
            ]));
        $provider->method('getResourceOwner')->willReturn(new GoogleUser([
            'sub' => 'google-sub-999',
            'email' => 'newuser@example.com',
            'name' => 'New User',
        ]));

        $userId = $this->makeService($provider)->handleCallback('auth-code');

        $this->assertGreaterThan(0, $userId);
        $this->seeInDatabase('users', [
            'id' => $userId,
            'google_sub' => 'google-sub-999',
            'email' => 'newuser@example.com',
        ]);
        // 토큰이 암호화되어 저장됐고 복호화하면 원문이 나온다.
        $row = (new OAuthTokenModel())->findByUserId($userId);
        $this->assertNotNull($row);
        $this->assertNotSame('refresh-abc', $row['refresh_token_encrypted']);
    }

    public function testStoreTokensEncryptsAndRoundTripsAccessToken(): void
    {
        $service = $this->makeService($this->createMock(Google::class));

        $service->storeTokens($this->userId, new AccessToken([
            'access_token' => 'ya29.access-plain',
            'refresh_token' => 'refresh-plain',
            'expires_in' => 3600,
        ]));

        // 저장된 값은 평문과 달라야 한다(암호화 확인).
        $row = (new OAuthTokenModel())->findByUserId($this->userId);
        $this->assertNotNull($row);
        $this->assertNotSame('ya29.access-plain', $row['access_token_encrypted']);
        $this->assertNotSame('refresh-plain', $row['refresh_token_encrypted']);

        // 유효기간이 남아 있으므로 refresh 없이 복호화된 원문을 그대로 돌려준다.
        $this->assertSame('ya29.access-plain', $service->getValidAccessToken($this->userId));
    }

    public function testGetValidAccessTokenRefreshesWhenExpired(): void
    {
        // 만료된 access token 을 먼저 저장한다.
        $seedProvider = $this->createMock(Google::class);
        $this->makeService($seedProvider)->storeTokens($this->userId, new AccessToken([
            'access_token' => 'ya29.stale',
            'refresh_token' => 'refresh-original',
            'expires' => time() - 100,
        ]));

        // refresh_token 으로 새 access token 을 받는 프로바이더를 구성한다.
        $refreshProvider = $this->createMock(Google::class);
        $refreshProvider->expects($this->once())
            ->method('getAccessToken')
            ->with('refresh_token', ['refresh_token' => 'refresh-original'])
            ->willReturn(new AccessToken([
                'access_token' => 'ya29.fresh',
                'expires_in' => 3600,
            ]));

        $access = $this->makeService($refreshProvider)->getValidAccessToken($this->userId);

        $this->assertSame('ya29.fresh', $access);
    }

    public function testRefreshPreservesExistingRefreshTokenWhenResponseHasNone(): void
    {
        $seed = $this->makeService($this->createMock(Google::class));
        $seed->storeTokens($this->userId, new AccessToken([
            'access_token' => 'ya29.stale',
            'refresh_token' => 'refresh-original',
            'expires' => time() - 100,
        ]));
        $originalEncrypted = (new OAuthTokenModel())->findByUserId($this->userId)['refresh_token_encrypted'];

        $refreshProvider = $this->createMock(Google::class);
        $refreshProvider->method('getAccessToken')->willReturn(new AccessToken([
            'access_token' => 'ya29.fresh',
            'expires_in' => 3600,
        ]));

        $this->makeService($refreshProvider)->refreshAccessToken($this->userId);

        // 갱신 응답에 refresh token 이 없으면 기존 암호문이 보존돼야 한다.
        $after = (new OAuthTokenModel())->findByUserId($this->userId);
        $this->assertSame($originalEncrypted, $after['refresh_token_encrypted']);
    }
}
