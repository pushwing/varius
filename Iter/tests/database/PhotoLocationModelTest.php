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
}
