<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PhotoLocationModel;
use App\Models\UserModel;
use App\Services\Ingest\PhotoLocation;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class PhotoControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $refresh = true;
    protected $namespace = 'App';

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (new UserModel())->upsertByGoogleSub('sub-photo', 'photo@example.com', 'Photo');
    }

    /**
     * 사용자 소유 사진(임시 JPEG 썸네일 포함)을 시드하고 [id, 썸네일경로] 를 반환한다.
     *
     * @return array{int, string}
     */
    private function seedPhoto(): array
    {
        $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_') . '.jpg';
        $im = imagecreatetruecolor(300, 200);
        imagejpeg($im, $thumbPath);

        (new PhotoLocationModel())->saveMany($this->userId, [
            new PhotoLocation('pm1', 37.5, 127.0, '2024-03-15 09:00:00', $thumbPath),
        ]);
        $id = (int) (new PhotoLocationModel())->where('source_item_id', 'pm1')->first()['id'];

        return [$id, $thumbPath];
    }

    public function testRotateRequiresLogin(): void
    {
        $result = $this->post('photos/1/rotate', ['direction' => 'right']);

        $result->assertStatus(401);
    }

    public function testRotateRejectsInvalidDirection(): void
    {
        [$id] = $this->seedPhoto();

        $result = $this->withSession(['user_id' => $this->userId])
            ->post('photos/' . $id . '/rotate', ['direction' => 'upside-down']);

        $result->assertStatus(422);
    }

    public function testRotateReturns404WhenNotOwned(): void
    {
        $result = $this->withSession(['user_id' => $this->userId])
            ->post('photos/999999/rotate', ['direction' => 'right']);

        $result->assertStatus(404);
    }

    public function testRotatePersistsRotatedThumbnail(): void
    {
        [$id, $thumbPath] = $this->seedPhoto();

        try {
            $result = $this->withSession(['user_id' => $this->userId])
                ->post('photos/' . $id . '/rotate', ['direction' => 'right']);

            $result->assertStatus(200);
            [$width, $height] = (array) getimagesize($thumbPath);
            $this->assertSame(200, $width);
            $this->assertSame(300, $height);
        } finally {
            if (is_file($thumbPath)) {
                unlink($thumbPath);
            }
        }
    }

    public function testDeleteRequiresLogin(): void
    {
        $result = $this->post('photos/1/delete');

        $result->assertStatus(401);
    }

    public function testDeleteReturns404WhenNotOwned(): void
    {
        $otherId = (new UserModel())->upsertByGoogleSub('sub-photo-2', 'photo2@example.com', 'Photo2');
        [$id, $thumbPath] = $this->seedPhoto();

        try {
            $result = $this->withSession(['user_id' => $otherId])->post('photos/' . $id . '/delete');

            $result->assertStatus(404);
            // 남의 사진은 그대로 남아야 한다.
            $this->seeInDatabase('photo_locations', ['id' => $id]);
        } finally {
            if (is_file($thumbPath)) {
                unlink($thumbPath);
            }
        }
    }

    public function testDeleteRemovesRowAndThumbnail(): void
    {
        [$id, $thumbPath] = $this->seedPhoto();

        $result = $this->withSession(['user_id' => $this->userId])->post('photos/' . $id . '/delete');

        $result->assertStatus(200);
        $this->dontSeeInDatabase('photo_locations', ['id' => $id]);
        $this->assertFileDoesNotExist($thumbPath);
    }
}
