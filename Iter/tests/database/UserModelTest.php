<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class UserModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $refresh = true;
    protected $namespace = 'App';

    public function testUpsertInsertsNewUserAndReturnsId(): void
    {
        $model = new UserModel();

        $id = $model->upsertByGoogleSub('sub-123', 'alice@example.com', 'Alice');

        $this->assertGreaterThan(0, $id);
        $this->seeInDatabase('users', [
            'id' => $id,
            'google_sub' => 'sub-123',
            'email' => 'alice@example.com',
            'name' => 'Alice',
        ]);
    }

    public function testUpsertUpdatesExistingUserWithoutDuplicating(): void
    {
        $model = new UserModel();

        $firstId = $model->upsertByGoogleSub('sub-123', 'old@example.com', 'Old Name');
        $secondId = $model->upsertByGoogleSub('sub-123', 'new@example.com', 'New Name');

        $this->assertSame($firstId, $secondId);
        $this->assertSame(1, $model->where('google_sub', 'sub-123')->countAllResults());
        $this->seeInDatabase('users', [
            'id' => $firstId,
            'email' => 'new@example.com',
            'name' => 'New Name',
        ]);
    }
}
