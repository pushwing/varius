<?php

declare(strict_types=1);

use App\Libraries\JwtTokenService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Jwt as JwtConfig;

/**
 * @internal
 */
final class JwtTokenServiceTest extends CIUnitTestCase
{
    private function makeService(string $secret = 'test-secret-key-0123456789abcdef', int $ttl = 300, string $room = 'rtic-home'): JwtTokenService
    {
        $config               = new JwtConfig();
        $config->secret       = $secret;
        $config->roomEntryTtl = $ttl;
        $config->roomName     = $room;

        return new JwtTokenService($config);
    }

    public function testIssueRoomEntryTokenReturnsTokenRoomAndTtl(): void
    {
        $issued = $this->makeService(ttl: 300, room: 'rtic-home')->issueRoomEntryToken(7, 'user@example.com');

        $this->assertIsString($issued['token']);
        $this->assertNotEmpty($issued['token']);
        $this->assertSame(300, $issued['expiresIn']);
        $this->assertSame('rtic-home', $issued['room']);
    }

    public function testDecodedPayloadContainsExpectedClaims(): void
    {
        $service = $this->makeService(room: 'rtic-home');
        $issued  = $service->issueRoomEntryToken(7, 'user@example.com');

        $payload = $service->decode($issued['token']);

        $this->assertSame(7, $payload->sub);
        $this->assertSame('user@example.com', $payload->email);
        $this->assertSame('rtic-home', $payload->room);
        $this->assertSame('rtic', $payload->iss);
    }

    public function testTokenExpiresAfterConfiguredTtl(): void
    {
        $service = $this->makeService(ttl: 300);
        $before  = time();
        $issued  = $service->issueRoomEntryToken(1, 'a@example.com');
        $after   = time();

        $payload = $service->decode($issued['token']);

        $this->assertGreaterThanOrEqual($before + 300, $payload->exp);
        $this->assertLessThanOrEqual($after + 300, $payload->exp);
    }

    public function testDecodeThrowsOnInvalidToken(): void
    {
        $this->expectException(\Exception::class);
        $this->makeService()->decode('invalid.token.here');
    }

    public function testDecodeThrowsOnWrongSecret(): void
    {
        $issued = $this->makeService(secret: 'secret-a-0123456789abcdef-0123456789')->issueRoomEntryToken(1, 'a@example.com');

        $this->expectException(\Exception::class);
        $this->makeService(secret: 'secret-b-0123456789abcdef-0123456789')->decode($issued['token']);
    }

    public function testThrowsWhenSecretIsEmpty(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->makeService(secret: '')->issueRoomEntryToken(1, 'a@example.com');
    }
}
