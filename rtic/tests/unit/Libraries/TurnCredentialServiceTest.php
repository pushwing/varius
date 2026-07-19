<?php

declare(strict_types=1);

use App\Libraries\TurnCredentialService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Turn as TurnConfig;

/**
 * @internal
 */
final class TurnCredentialServiceTest extends CIUnitTestCase
{
    private function makeService(string $secret = 'test-turn-secret-0123456789abcdef', int $ttl = 300, string $realm = 'rtic-home'): TurnCredentialService
    {
        $config                = new TurnConfig();
        $config->secret        = $secret;
        $config->credentialTtl = $ttl;
        $config->realm         = $realm;

        return new TurnCredentialService($config);
    }

    public function testIssueReturnsUsernameCredentialTtlRealm(): void
    {
        $issued = $this->makeService(ttl: 300, realm: 'rtic-home')->issue('user-7');

        $this->assertMatchesRegularExpression('/^\d+:user-7$/', $issued['username']);
        $this->assertNotEmpty($issued['credential']);
        $this->assertSame(300, $issued['ttl']);
        $this->assertSame('rtic-home', $issued['realm']);
    }

    public function testUsernameExpiryEncodesTtlFromNow(): void
    {
        $before = time();
        $issued = $this->makeService(ttl: 300)->issue('user-7');
        $after  = time();

        [$expiry] = explode(':', $issued['username'], 2);

        $this->assertGreaterThanOrEqual($before + 300, (int) $expiry);
        $this->assertLessThanOrEqual($after + 300, (int) $expiry);
    }

    public function testCredentialMatchesHmacSha1OfUsername(): void
    {
        $secret = 'test-turn-secret-0123456789abcdef';
        $issued = $this->makeService(secret: $secret)->issue('user-7');

        $expected = base64_encode(hash_hmac('sha1', $issued['username'], $secret, true));

        $this->assertSame($expected, $issued['credential']);
    }

    public function testDifferentSecretsProduceDifferentCredentials(): void
    {
        $issuedA = $this->makeService(secret: 'secret-a-0123456789abcdef-0123456789')->issue('user-7');
        $issuedB = $this->makeService(secret: 'secret-b-0123456789abcdef-0123456789')->issue('user-7');

        $this->assertNotSame($issuedA['credential'], $issuedB['credential']);
    }

    public function testThrowsWhenSecretIsEmpty(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->makeService(secret: '')->issue('user-7');
    }
}
