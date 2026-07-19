<?php

declare(strict_types=1);

use App\Libraries\LiveKitAccessTokenService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\LiveKit as LiveKitConfig;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * @internal
 */
final class LiveKitAccessTokenServiceTest extends CIUnitTestCase
{
    private function makeService(
        string $apiKey = 'test-api-key',
        string $apiSecret = 'test-api-secret-0123456789abcdef',
        string $url = 'ws://localhost:7880',
        int $ttl = 300,
        string $room = 'rtic-home',
    ): LiveKitAccessTokenService {
        $config            = new LiveKitConfig();
        $config->apiKey    = $apiKey;
        $config->apiSecret = $apiSecret;
        $config->url       = $url;
        $config->tokenTtl  = $ttl;
        $config->roomName  = $room;

        return new LiveKitAccessTokenService($config);
    }

    public function testIssueReturnsTokenUrlRoomAndTtl(): void
    {
        $issued = $this->makeService(url: 'ws://localhost:7880', ttl: 300, room: 'rtic-home')->issue('42');

        $this->assertNotEmpty($issued['token']);
        $this->assertSame('ws://localhost:7880', $issued['url']);
        $this->assertSame('rtic-home', $issued['room']);
        $this->assertSame(300, $issued['expiresIn']);
    }

    public function testTokenClaimsMatchLiveKitVideoGrantFormat(): void
    {
        $secret = 'test-api-secret-0123456789abcdef';
        $issued = $this->makeService(apiKey: 'my-api-key', apiSecret: $secret, room: 'rtic-home')->issue('42');

        $payload = JWT::decode($issued['token'], new Key($secret, 'HS256'));

        $this->assertSame('my-api-key', $payload->iss);
        $this->assertSame('42', $payload->sub);
        $this->assertTrue($payload->video->roomJoin);
        $this->assertSame('rtic-home', $payload->video->room);
        $this->assertTrue($payload->video->canPublish);
        $this->assertTrue($payload->video->canSubscribe);
        $this->assertTrue($payload->video->canPublishData);
    }

    public function testTokenExpiresAfterConfiguredTtl(): void
    {
        $before = time();
        $issued = $this->makeService(ttl: 300)->issue('42');
        $after  = time();

        $payload = JWT::decode($issued['token'], new Key('test-api-secret-0123456789abcdef', 'HS256'));

        $this->assertGreaterThanOrEqual($before + 300, $payload->exp);
        $this->assertLessThanOrEqual($after + 300, $payload->exp);
    }

    public function testTokenHasUniqueJtiAcrossCalls(): void
    {
        // 이슈 #10 — 재로그인 시 매번 새 토큰이 발급돼야 한다. exp/iat/claims가
        // 같은 초(second) 안에서는 전부 동일해 HS256 서명까지 동일해지므로
        // (재현: jti 없이 같은 초에 두 번 issue()하면 바이트까지 같은 토큰이
        // 나옴), 매 발급마다 고유한 jti를 넣어 토큰을 구분한다.
        $service = $this->makeService();

        $first  = $service->issue('42');
        $second = $service->issue('42');

        $secret  = 'test-api-secret-0123456789abcdef';
        $keyFor  = static fn () => new Key($secret, 'HS256');
        $payload1 = JWT::decode($first['token'], $keyFor());
        $payload2 = JWT::decode($second['token'], $keyFor());

        $this->assertNotSame($first['token'], $second['token']);
        $this->assertNotSame($payload1->jti, $payload2->jti);
    }

    public function testThrowsWhenApiKeyIsEmpty(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->makeService(apiKey: '')->issue('42');
    }

    public function testThrowsWhenApiSecretIsEmpty(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->makeService(apiSecret: '')->issue('42');
    }
}
