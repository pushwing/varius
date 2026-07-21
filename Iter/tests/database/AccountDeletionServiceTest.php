<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\OAuthTokenModel;
use App\Models\PhotoLocationModel;
use App\Models\UserModel;
use App\Services\AccountDeletionService;
use App\Services\GooglePhotosAuthService;
use App\Services\Ingest\PhotoLocation;
use App\Support\Filesystem;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class AccountDeletionServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $refresh = true;
    protected $namespace = 'App';

    private string $thumbnailBaseDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->thumbnailBaseDir = sys_get_temp_dir() . '/iter_del_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        Filesystem::removeDirectory($this->thumbnailBaseDir);
        parent::tearDown();
    }

    private function service(): AccountDeletionService
    {
        // Google 토큰 폐기는 외부 호출이라 이 테스트에서는 대역으로 대체(로컬 삭제만 검증).
        $auth = $this->createMock(GooglePhotosAuthService::class);

        return new AccountDeletionService(
            new PhotoLocationModel(),
            new OAuthTokenModel(),
            new UserModel(),
            $auth,
            $this->thumbnailBaseDir,
        );
    }

    public function testDeleteAllUserDataRemovesRowsAndThumbnails(): void
    {
        $userId = (new UserModel())->upsertByGoogleSub('sub-del', 'del@example.com', 'Del');
        (new OAuthTokenModel())->upsertByUserId($userId, [
            'refresh_token_encrypted' => 'enc-refresh',
            'access_token_encrypted' => 'enc-access',
            'expires_at' => '2030-01-01 00:00:00',
        ]);
        (new PhotoLocationModel())->saveMany($userId, [
            new PhotoLocation('media-1', 37.5, 127.0, '2024-03-15 09:00:00'),
            new PhotoLocation('media-2', 37.6, 127.1, '2024-03-15 12:00:00'),
        ]);

        $userThumbDir = $this->thumbnailBaseDir . '/' . $userId;
        mkdir($userThumbDir, 0755, true);
        file_put_contents($userThumbDir . '/media-1.jpg', 'x');

        $this->service()->deleteAllUserData($userId);

        $this->dontSeeInDatabase('photo_locations', ['user_id' => $userId]);
        $this->dontSeeInDatabase('oauth_tokens', ['user_id' => $userId]);
        $this->dontSeeInDatabase('users', ['id' => $userId]);
        $this->assertDirectoryDoesNotExist($userThumbDir);
    }

    public function testDeleteAllUserDataInvokesTokenRevocation(): void
    {
        $userId = (new UserModel())->upsertByGoogleSub('sub-rev', 'rev@example.com', 'Rev');

        $auth = $this->createMock(GooglePhotosAuthService::class);
        $auth->expects($this->once())->method('revokeTokens')->with($userId);

        $service = new AccountDeletionService(
            new PhotoLocationModel(),
            new OAuthTokenModel(),
            new UserModel(),
            $auth,
            $this->thumbnailBaseDir,
        );

        $service->deleteAllUserData($userId);

        $this->dontSeeInDatabase('users', ['id' => $userId]);
    }

    public function testDeleteAllUserDataOnlyAffectsTargetUser(): void
    {
        $victim = (new UserModel())->upsertByGoogleSub('sub-keep', 'keep@example.com', 'Keep');
        $target = (new UserModel())->upsertByGoogleSub('sub-drop', 'drop@example.com', 'Drop');

        (new PhotoLocationModel())->saveMany($victim, [
            new PhotoLocation('keep-1', 37.5, 127.0, '2024-03-15 09:00:00'),
        ]);
        (new PhotoLocationModel())->saveMany($target, [
            new PhotoLocation('drop-1', 37.5, 127.0, '2024-03-15 09:00:00'),
        ]);

        $this->service()->deleteAllUserData($target);

        $this->seeInDatabase('users', ['id' => $victim]);
        $this->seeInDatabase('photo_locations', ['user_id' => $victim]);
        $this->dontSeeInDatabase('photo_locations', ['user_id' => $target]);
    }
}
