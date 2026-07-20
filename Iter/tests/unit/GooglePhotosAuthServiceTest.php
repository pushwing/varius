<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\OAuthTokenModel;
use App\Models\UserModel;
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
            'scopes' => [
                'openid',
                'email',
                'profile',
                'https://www.googleapis.com/auth/photospicker.mediaitems.readonly',
            ],
        ]);

        $url = $this->makeService($provider)->getAuthorizationUrl('state-token-abc');

        $this->assertStringContainsString('client_id=test-client-id', $url);
        $this->assertStringContainsString('state=state-token-abc', $url);
        $this->assertStringContainsString('access_type=offline', $url);
        $this->assertStringContainsString('prompt=consent', $url);
        $this->assertStringContainsString('photospicker.mediaitems.readonly', rawurldecode($url));
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
}
