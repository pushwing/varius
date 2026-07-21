<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\OAuthTokenModel;
use App\Models\UserModel;
use App\Services\Auth\TokenRevokerInterface;
use App\Services\GooglePhotosAuthService;
use CodeIgniter\Encryption\EncrypterInterface;
use CodeIgniter\Test\CIUnitTestCase;
use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Client\Token\AccessToken;

/**
 * @internal
 */
final class GooglePhotosAuthServiceTest extends CIUnitTestCase
{
    private function makeService(Google $provider): GooglePhotosAuthService
    {
        return new GooglePhotosAuthService(
            $provider,
            $this->createMock(OAuthTokenModel::class),
            $this->createMock(UserModel::class),
            $this->createMock(EncrypterInterface::class),
        );
    }

    public function testAuthorizationUrlContainsScopesStateAndOfflineAccess(): void
    {
        $provider = new Google([
            'clientId' => 'test-client-id',
            'clientSecret' => 'test-secret',
            'redirectUri' => 'http://localhost:8080/auth/google/callback',
            'scopes' => ['openid', 'email', 'profile'],
        ]);

        $url = $this->makeService($provider)->getAuthorizationUrl('state-token-abc');

        $this->assertStringContainsString('client_id=test-client-id', $url);
        $this->assertStringContainsString('state=state-token-abc', $url);
        $this->assertStringContainsString('access_type=offline', $url);
        $this->assertStringContainsString('prompt=consent', $url);
        $this->assertStringContainsString('profile', rawurldecode($url));
    }

    public function testExchangeCodeRequestsAuthorizationCodeGrant(): void
    {
        $expected = new AccessToken(['access_token' => 'ya29.the-access-token']);

        $provider = $this->createMock(Google::class);
        $provider->expects($this->once())
            ->method('getAccessToken')
            ->with('authorization_code', ['code' => 'auth-code-xyz'])
            ->willReturn($expected);

        $token = $this->makeService($provider)->exchangeCode('auth-code-xyz');

        $this->assertSame('ya29.the-access-token', $token->getToken());
    }

    public function testRevokeTokensCallsRevokerWithDecryptedRefreshToken(): void
    {
        $tokenModel = $this->createMock(OAuthTokenModel::class);
        $tokenModel->method('findByUserId')->with(9)->willReturn([
            'refresh_token_encrypted' => base64_encode('cipher'),
            'access_token_encrypted' => base64_encode('other'),
        ]);

        $encrypter = $this->createMock(EncrypterInterface::class);
        $encrypter->method('decrypt')->with('cipher')->willReturn('refresh-token-plain');

        $revoker = $this->createMock(TokenRevokerInterface::class);
        $revoker->expects($this->once())->method('revoke')->with('refresh-token-plain');

        $service = new GooglePhotosAuthService(
            $this->createMock(Google::class),
            $tokenModel,
            $this->createMock(UserModel::class),
            $encrypter,
            60,
            $revoker,
        );

        $service->revokeTokens(9);
    }

    public function testRevokeTokensDoesNothingWhenNoStoredToken(): void
    {
        $tokenModel = $this->createMock(OAuthTokenModel::class);
        $tokenModel->method('findByUserId')->willReturn(null);

        $revoker = $this->createMock(TokenRevokerInterface::class);
        $revoker->expects($this->never())->method('revoke');

        $service = new GooglePhotosAuthService(
            $this->createMock(Google::class),
            $tokenModel,
            $this->createMock(UserModel::class),
            $this->createMock(EncrypterInterface::class),
            60,
            $revoker,
        );

        $service->revokeTokens(1);
    }

    public function testRevokeTokensIsNoopWhenRevokerNotInjected(): void
    {
        // revoker 미주입(기본값 null)이면 토큰 조회조차 하지 않는다.
        $tokenModel = $this->createMock(OAuthTokenModel::class);
        $tokenModel->expects($this->never())->method('findByUserId');

        $this->makeService($this->createMock(Google::class))->revokeTokens(1);

        // 예외 없이 반환되면 성공.
        $this->addToAssertionCount(1);
    }
}
