<?php

declare(strict_types=1);

use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

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
        putenv('jwt.secret=feature-test-secret-0123456789abcdef');
        $_ENV['jwt.secret'] = 'feature-test-secret-0123456789abcdef';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('jwt.secret');
        unset($_ENV['jwt.secret']);
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
        $this->assertSame('Bearer', $data['data']['token_type']);
        $this->assertSame('rtic-home', $data['data']['room']);
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
