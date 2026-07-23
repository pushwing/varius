<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\PhotoLocationModel;
use App\Models\UserModel;
use App\Services\Ingest\PhotoLocation;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class PhotoLocationModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $refresh = true;
    protected $namespace = 'App';

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        // photo_locations.user_id 는 users FK 이므로 사용자를 먼저 시드한다.
        $this->userId = (new UserModel())->upsertByGoogleSub('sub-loc', 'loc@example.com', 'Loc');
    }

    public function testSaveManyInsertsRows(): void
    {
        $saved = (new PhotoLocationModel())->saveMany($this->userId, [
            new PhotoLocation('media-1', 37.5, 127.0, '2024-03-15 09:00:00'),
            new PhotoLocation('media-2', 37.6, 127.1, '2024-03-15 12:00:00'),
        ]);

        $this->assertSame(2, $saved);
        $this->seeInDatabase('photo_locations', [
            'user_id' => $this->userId,
            'source_item_id' => 'media-1',
        ]);
        $this->seeInDatabase('photo_locations', [
            'user_id' => $this->userId,
            'source_item_id' => 'media-2',
        ]);
    }

    public function testSaveManyPersistsThumbnailPath(): void
    {
        (new PhotoLocationModel())->saveMany($this->userId, [
            new PhotoLocation('media-thumb', 37.5, 127.0, '2024-03-15 09:00:00', '/thumbs/media-thumb.jpg'),
        ]);

        $this->seeInDatabase('photo_locations', [
            'source_item_id' => 'media-thumb',
            'thumbnail_path' => '/thumbs/media-thumb.jpg',
        ]);
    }

    public function testSaveManySkipsDuplicateMediaItemIds(): void
    {
        $model = new PhotoLocationModel();
        $model->saveMany($this->userId, [new PhotoLocation('dup', 37.5, 127.0, '2024-03-15 09:00:00')]);

        // 같은 source_item_id 재적재는 건너뛴다(idempotent).
        $saved = $model->saveMany($this->userId, [
            new PhotoLocation('dup', 38.0, 128.0, '2024-03-16 09:00:00'),
            new PhotoLocation('fresh', 37.7, 127.2, '2024-03-16 10:00:00'),
        ]);

        $this->assertSame(1, $saved);
        $this->assertSame(1, $model->where('source_item_id', 'dup')->countAllResults());
        $this->seeInDatabase('photo_locations', ['source_item_id' => 'fresh']);
    }

    public function testThumbnailPathForReturnsPathWhenOwnedByUser(): void
    {
        $model = new PhotoLocationModel();
        $model->saveMany($this->userId, [
            new PhotoLocation('media-thumb', 37.5, 127.0, '2024-03-15 09:00:00', '/thumbs/media-thumb.jpg'),
        ]);
        $id = (int) $model->where('source_item_id', 'media-thumb')->first()['id'];

        $this->assertSame('/thumbs/media-thumb.jpg', $model->thumbnailPathFor($id, $this->userId));
    }

    public function testThumbnailPathForReturnsNullWhenOwnedByAnotherUser(): void
    {
        $otherUserId = (new UserModel())->upsertByGoogleSub('sub-loc-other', 'other@example.com', 'Other');

        $model = new PhotoLocationModel();
        $model->saveMany($this->userId, [
            new PhotoLocation('media-thumb', 37.5, 127.0, '2024-03-15 09:00:00', '/thumbs/media-thumb.jpg'),
        ]);
        $id = (int) $model->where('source_item_id', 'media-thumb')->first()['id'];

        $this->assertNull($model->thumbnailPathFor($id, $otherUserId));
    }

    public function testThumbnailPathForReturnsNullWhenIdDoesNotExist(): void
    {
        $model = new PhotoLocationModel();

        $this->assertNull($model->thumbnailPathFor(999999, $this->userId));
    }

    public function testFindByUserBetweenReturnsOnlyRangeOrdered(): void
    {
        $otherUserId = (new UserModel())->upsertByGoogleSub('sub-loc-date', 'date@example.com', 'Date');

        $model = new PhotoLocationModel();
        $model->saveMany($this->userId, [
            new PhotoLocation('d2', 37.6, 127.1, '2024-03-15 12:00:00'),
            new PhotoLocation('d1', 37.5, 127.0, '2024-03-15 09:00:00'),
            new PhotoLocation('d3', 35.1, 129.0, '2024-03-16 08:00:00'),
        ]);
        $model->saveMany($otherUserId, [
            new PhotoLocation('d4', 37.5, 127.0, '2024-03-15 10:00:00'),
        ]);

        $rows = $model->findByUserBetween($this->userId, '2024-03-14 15:00:00', '2024-03-15 14:59:59');

        // 해당 사용자의 범위 내 좌표만, 촬영 시각 오름차순으로.
        $this->assertCount(2, $rows);
        $this->assertSame('d1', $rows[0]['source_item_id']);
        $this->assertSame('d2', $rows[1]['source_item_id']);
    }

    public function testCountBetweenCountsOnlyWithinRangeForUser(): void
    {
        $otherUserId = (new UserModel())->upsertByGoogleSub('sub-loc-count', 'count@example.com', 'Count');

        $model = new PhotoLocationModel();
        $model->saveMany($this->userId, [
            new PhotoLocation('c1', 37.5, 127.0, '2024-03-15 09:00:00'),
            new PhotoLocation('c2', 37.6, 127.1, '2024-03-16 09:00:00'),
            new PhotoLocation('c3', 37.6, 127.1, '2024-03-20 09:00:00'), // 범위 밖.
        ]);
        $model->saveMany($otherUserId, [
            new PhotoLocation('c4', 37.5, 127.0, '2024-03-15 10:00:00'),
        ]);

        $count = $model->countBetween($this->userId, '2024-03-15 00:00:00', '2024-03-16 23:59:59');

        $this->assertSame(2, $count);
    }

    public function testFirstThumbnailBetweenReturnsEarliestPhotoWithThumbnail(): void
    {
        $model = new PhotoLocationModel();
        $model->saveMany($this->userId, [
            new PhotoLocation('f1', 37.5, 127.0, '2024-03-15 09:00:00'), // 썸네일 없음.
            new PhotoLocation('f2', 37.6, 127.1, '2024-03-15 10:00:00', '/thumbs/f2.jpg'),
            new PhotoLocation('f3', 37.6, 127.1, '2024-03-15 11:00:00', '/thumbs/f3.jpg'),
        ]);
        $f2Id = (int) $model->where('source_item_id', 'f2')->first()['id'];

        $id = $model->firstThumbnailBetween($this->userId, '2024-03-15 00:00:00', '2024-03-15 23:59:59');

        $this->assertSame($f2Id, $id);
    }

    public function testFirstThumbnailBetweenReturnsNullWhenNoneHaveThumbnails(): void
    {
        $model = new PhotoLocationModel();
        $model->saveMany($this->userId, [
            new PhotoLocation('n1', 37.5, 127.0, '2024-03-15 09:00:00'),
        ]);

        $this->assertNull($model->firstThumbnailBetween($this->userId, '2024-03-15 00:00:00', '2024-03-15 23:59:59'));
    }

    public function testFindUnresolvedBatchFiltersCorrectly(): void
    {
        $otherUserId = (new UserModel())->upsertByGoogleSub('sub-loc-backfill', 'backfill@example.com', 'Backfill');

        $model = new PhotoLocationModel();
        // 포함될 행: 좌표 있음 + country_code NULL
        $model->saveMany($this->userId, [
            new PhotoLocation('include-1', 37.5, 127.0, '2024-03-15 09:00:00'),
            new PhotoLocation('include-2', 37.6, 127.1, '2024-03-15 10:00:00'),
        ]);
        // 제외될 행: country_code 이미 채워짐
        $model->saveMany($this->userId, [
            (new PhotoLocation('exclude-region', 35.1, 129.0, '2024-03-15 11:00:00'))->withRegion('KR', 'KR-11'),
        ]);

        // 제외될 행: 좌표 없음 (서버에 추가 삽입)
        $model->insert([
            'user_id' => $this->userId,
            'source_item_id' => 'exclude-no-lat',
            'lat' => null,
            'lng' => 127.0,
            'taken_at' => '2024-03-15 12:00:00',
        ]);

        // 다른 사용자 행: 포함됨 (백필은 전체 사용자 대상)
        $model->saveMany($otherUserId, [
            new PhotoLocation('other-user-1', 38.0, 128.0, '2024-03-15 13:00:00'),
        ]);

        $rows = $model->findUnresolvedBatch(0, 100);

        // 좌표 있음 + country_code NULL 인 행만 3건 반환
        $this->assertCount(3, $rows);
    }

    public function testFindUnresolvedBatchCursorPagination(): void
    {
        $model = new PhotoLocationModel();
        // 5개 행 생성
        $model->saveMany($this->userId, [
            new PhotoLocation('b1', 37.5, 127.0, '2024-03-15 09:00:00'),
            new PhotoLocation('b2', 37.6, 127.1, '2024-03-15 10:00:00'),
            new PhotoLocation('b3', 37.7, 127.2, '2024-03-15 11:00:00'),
            new PhotoLocation('b4', 37.8, 127.3, '2024-03-15 12:00:00'),
            new PhotoLocation('b5', 37.9, 127.4, '2024-03-15 13:00:00'),
        ]);

        // 첫 배치: limit=2
        $batch1 = $model->findUnresolvedBatch(0, 2);
        $this->assertCount(2, $batch1);
        $this->assertLessThan($batch1[1]['id'], $batch1[0]['id']); // id 오름차순 정렬 확인
        $lastIdBatch1 = $batch1[1]['id'];

        // 두 번째 배치: afterId = 첫 배치의 마지막 id
        $batch2 = $model->findUnresolvedBatch($lastIdBatch1, 2);
        $this->assertCount(2, $batch2);
        // 두 번째 배치의 모든 id > lastIdBatch1 확인
        foreach ($batch2 as $row) {
            $this->assertGreaterThan($lastIdBatch1, $row['id']);
        }

        // 세 번째 배치: 남은 1개
        $lastIdBatch2 = $batch2[1]['id'];
        $batch3 = $model->findUnresolvedBatch($lastIdBatch2, 2);
        $this->assertCount(1, $batch3);
    }

    public function testFindUnresolvedBatchLimitEnforced(): void
    {
        $model = new PhotoLocationModel();
        // 10개 행 생성
        $locations = [];
        for ($i = 1; $i <= 10; $i++) {
            $locations[] = new PhotoLocation("limit-{$i}", 37.0 + ($i * 0.01), 127.0 + ($i * 0.01), '2024-03-15 09:00:00');
        }
        $model->saveMany($this->userId, $locations);

        // limit=3 일 때 정확히 3개만 반환
        $rows = $model->findUnresolvedBatch(0, 3);
        $this->assertCount(3, $rows);

        // 다음 배치도 limit 준수
        $nextRows = $model->findUnresolvedBatch($rows[2]['id'], 3);
        $this->assertCount(3, $nextRows);
    }

    public function testFindUnresolvedBatchReturnTypesCasted(): void
    {
        $model = new PhotoLocationModel();
        $model->saveMany($this->userId, [
            new PhotoLocation('type-check', 37.5123, 127.9876, '2024-03-15 09:00:00'),
        ]);

        $rows = $model->findUnresolvedBatch(0, 100);
        $this->assertCount(1, $rows);

        $row = $rows[0];
        // id 는 int 타입 캐스팅 검증
        $this->assertIsInt($row['id']);
        // lat, lng 는 float 타입 캐스팅 검증
        $this->assertIsFloat($row['lat']);
        $this->assertIsFloat($row['lng']);
        // 정밀도 확인
        $this->assertEqualsWithDelta(37.5123, $row['lat'], 0.0001);
        $this->assertEqualsWithDelta(127.9876, $row['lng'], 0.0001);
    }

    public function testFindUnresolvedBatchReturnsEmptyWhenNothingUnresolved(): void
    {
        $model = new PhotoLocationModel();
        // 모든 행이 country_code 채워진 상태
        $model->saveMany($this->userId, [
            (new PhotoLocation('all-resolved', 37.5, 127.0, '2024-03-15 09:00:00'))->withRegion('KR', 'KR-11'),
        ]);

        $rows = $model->findUnresolvedBatch(0, 100);
        $this->assertCount(0, $rows);
    }
}
