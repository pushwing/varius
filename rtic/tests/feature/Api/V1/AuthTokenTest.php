<?php

declare(strict_types=1);

use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * @internal
 */
final class AuthTokenTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $refresh   = true;
    protected $namespace = 'App';

    private const PASSWORD = 'correct-horse-battery-staple';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('livekit.apiKey', 'feature-test-api-key');
        $this->setEnv('livekit.apiSecret', 'feature-test-secret-0123456789abcdef');
        $this->setEnv('livekit.url', 'ws://localhost:7880');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->unsetEnv('livekit.apiKey');
        $this->unsetEnv('livekit.apiSecret');
        $this->unsetEnv('livekit.url');
    }

    private function setEnv(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
    }

    private function unsetEnv(string $key): void
    {
        putenv($key);
        unset($_ENV[$key]);
    }

    private function seedUser(string $email = 'user@example.com'): void
    {
        model(UserModel::class)->insert([
            'email'         => $email,
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
        ]);
    }

    public function testValidCredentialsReturnRoomEntryToken(): void
    {
        $this->seedUser('user@example.com');

        $result = $this->withBodyFormat('json')->post('api/v1/tokens', [
            'email'    => 'user@example.com',
            'password' => self::PASSWORD,
        ]);

        $result->assertStatus(201);
        $result->assertJSONFragment(['status' => 'success']);

        $data = json_decode($result->getJSON(), true);
        $this->assertArrayHasKey('access_token', $data['data']);
        $this->assertSame('ws://localhost:7880', $data['data']['livekit_url']);
        $this->assertSame('rtic-home', $data['data']['room']);
    }

    public function testReloginIssuesFreshIndependentToken(): void
    {
        // 이슈 #10 — "룸 토큰 TTL 만료/재발급 시나리오": 클라이언트가 다시
        // 로그인하면(예: 이전 토큰 만료 후) 매번 새 토큰이 독립적으로
        // 발급되고, 두 토큰 모두 유효해야 한다(세션 상태를 서버가 들고
        // 있지 않는 stateless 발급이므로 이전 토큰을 무효화하지 않음).
        $this->seedUser('user@example.com');

        $first = $this->withBodyFormat('json')->post('api/v1/tokens', [
            'email'    => 'user@example.com',
            'password' => self::PASSWORD,
        ]);
        $first->assertStatus(201);
        $firstToken = json_decode($first->getJSON(), true)['data']['access_token'];

        $second = $this->withBodyFormat('json')->post('api/v1/tokens', [
            'email'    => 'user@example.com',
            'password' => self::PASSWORD,
        ]);
        $second->assertStatus(201);
        $secondToken = json_decode($second->getJSON(), true)['data']['access_token'];

        $this->assertNotSame($firstToken, $secondToken);

        $secret = 'feature-test-secret-0123456789abcdef';
        foreach ([$firstToken, $secondToken] as $token) {
            $payload = JWT::decode($token, new Key($secret, 'HS256'));
            $this->assertTrue($payload->video->roomJoin);
            $this->assertSame('rtic-home', $payload->video->room);
        }
    }

    public function testWrongPasswordReturnsInvalidCredentials(): void
    {
        $this->seedUser('user@example.com');

        $result = $this->withBodyFormat('json')->post('api/v1/tokens', [
            'email'    => 'user@example.com',
            'password' => 'wrong-password',
        ]);

        $result->assertStatus(401);
        $result->assertJSONFragment(['status' => 'error', 'code' => 'INVALID_CREDENTIALS']);
    }

    public function testUnknownEmailReturnsInvalidCredentials(): void
    {
        $result = $this->withBodyFormat('json')->post('api/v1/tokens', [
            'email'    => 'nobody@example.com',
            'password' => self::PASSWORD,
        ]);

        $result->assertStatus(401);
        $result->assertJSONFragment(['status' => 'error', 'code' => 'INVALID_CREDENTIALS']);
    }

    public function testMissingFieldsReturnValidationError(): void
    {
        $result = $this->withBodyFormat('json')->post('api/v1/tokens', [
            'email' => 'user@example.com',
        ]);

        $result->assertStatus(422);
        $result->assertJSONFragment(['status' => 'error', 'code' => 'VALIDATION_ERROR']);
    }
}
