<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\PhotoLocationModel;
use App\Models\TripModel;
use App\Models\UserModel;
use App\Services\Ingest\PhotoLocation;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class TripModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $refresh = true;
    protected $namespace = 'App';

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (new UserModel())->upsertByGoogleSub('sub-trip', 'trip@example.com', 'Trip');
    }

    public function testInsertAndFindByUserOrdered(): void
    {
        $model = new TripModel();
        $model->insert([
            'user_id' => $this->userId, 'title' => '서울 여행', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-17', 'cover_photo_id' => null,
        ]);
        $model->insert([
            'user_id' => $this->userId, 'title' => '부산 여행', 'body' => '',
            'start_date' => '2024-05-01', 'end_date' => '2024-05-02', 'cover_photo_id' => null,
        ]);

        $trips = $model->findByUserOrdered($this->userId);

        // 최신 시작일순.
        $this->assertCount(2, $trips);
        $this->assertSame('부산 여행', $trips[0]['title']);
        $this->assertSame('서울 여행', $trips[1]['title']);
    }

    public function testFindOwnedReturnsNullForOtherUsersTrip(): void
    {
        $otherId = (new UserModel())->upsertByGoogleSub('sub-trip-2', 'trip2@example.com', 'Trip2');
        $model = new TripModel();
        $id = $model->insert([
            'user_id' => $otherId, 'title' => 'T', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-15', 'cover_photo_id' => null,
        ]);

        $this->assertNull($model->findOwned((int) $id, $this->userId));
        $this->assertNotNull($model->findOwned((int) $id, $otherId));
    }

    public function testOverlapsDetectsIntersectingRange(): void
    {
        $model = new TripModel();
        $model->insert([
            'user_id' => $this->userId, 'title' => 'T', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-17', 'cover_photo_id' => null,
        ]);

        // 부분 겹침(16일~20일)과 완전 포함(14일~18일) 모두 겹침으로 판정.
        $this->assertTrue($model->overlaps($this->userId, '2024-03-16', '2024-03-20'));
        $this->assertTrue($model->overlaps($this->userId, '2024-03-14', '2024-03-18'));
        // 바로 다음날부터 시작하면 겹치지 않음.
        $this->assertFalse($model->overlaps($this->userId, '2024-03-18', '2024-03-20'));
    }

    public function testOverlapsExcludesGivenIdForUpdateScenario(): void
    {
        $model = new TripModel();
        $id = $model->insert([
            'user_id' => $this->userId, 'title' => 'T', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-17', 'cover_photo_id' => null,
        ]);

        $this->assertTrue($model->overlaps($this->userId, '2024-03-15', '2024-03-17'));
        $this->assertFalse($model->overlaps($this->userId, '2024-03-15', '2024-03-17', (int) $id));
    }

    public function testOverlapsIsScopedToUser(): void
    {
        $otherId = (new UserModel())->upsertByGoogleSub('sub-trip-3', 'trip3@example.com', 'Trip3');
        $model = new TripModel();
        $model->insert([
            'user_id' => $otherId, 'title' => 'T', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-17', 'cover_photo_id' => null,
        ]);

        $this->assertFalse($model->overlaps($this->userId, '2024-03-15', '2024-03-17'));
    }

    public function testDeletingCoverPhotoNullsCoverPhotoIdViaForeignKey(): void
    {
        $photoModel = new PhotoLocationModel();
        $photoModel->saveMany($this->userId, [new PhotoLocation('p1', 37.5, 127.0, '2024-03-15 09:00:00')]);
        $photoId = (int) $photoModel->where('source_item_id', 'p1')->first()['id'];

        $model = new TripModel();
        $tripId = $model->insert([
            'user_id' => $this->userId, 'title' => 'T', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-15', 'cover_photo_id' => $photoId,
        ]);

        $photoModel->delete($photoId);

        $trip = $model->find((int) $tripId);
        $this->assertNull($trip['cover_photo_id']);
    }

    public function testDeleteRemovesTrip(): void
    {
        $model = new TripModel();
        $id = $model->insert([
            'user_id' => $this->userId, 'title' => 'T', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-15', 'cover_photo_id' => null,
        ]);

        $model->delete((int) $id);

        $this->assertNull($model->findOwned((int) $id, $this->userId));
    }
}
