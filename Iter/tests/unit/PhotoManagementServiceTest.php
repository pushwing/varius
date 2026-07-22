<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PhotoLocationModel;
use App\Services\PhotoManagementService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class PhotoManagementServiceTest extends CIUnitTestCase
{
    private string $thumbPath;

    protected function setUp(): void
    {
        parent::setUp();
        // 300x200 임시 JPEG 썸네일(회전 검증용 비대칭 크기).
        $this->thumbPath = tempnam(sys_get_temp_dir(), 'thumb_') . '.jpg';
        $im = imagecreatetruecolor(300, 200);
        imagejpeg($im, $this->thumbPath);
    }

    protected function tearDown(): void
    {
        if (is_file($this->thumbPath)) {
            unlink($this->thumbPath);
        }
        parent::tearDown();
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private function service(?array $row, ?int &$deletedId = null): PhotoManagementService
    {
        $model = $this->createMock(PhotoLocationModel::class);
        $model->method('findOwned')->willReturn($row);
        $model->method('delete')->willReturnCallback(function ($id) use (&$deletedId) {
            $deletedId = (int) $id;

            return true;
        });

        return new PhotoManagementService($model);
    }

    public function testRotateRightTurnsThumbnailClockwise(): void
    {
        $service = $this->service(['id' => 7, 'thumbnail_path' => $this->thumbPath]);

        $this->assertTrue($service->rotateThumbnail(7, 1, 'right'));

        // 300x200 → 90도 회전 → 200x300.
        [$width, $height] = (array) getimagesize($this->thumbPath);
        $this->assertSame(200, $width);
        $this->assertSame(300, $height);
    }

    public function testRotateReturnsFalseWhenNotOwnedOrMissing(): void
    {
        $service = $this->service(null);

        $this->assertFalse($service->rotateThumbnail(7, 1, 'left'));
    }

    public function testRotateReturnsFalseWhenThumbnailFileMissing(): void
    {
        $service = $this->service(['id' => 7, 'thumbnail_path' => '/no/such/file.jpg']);

        $this->assertFalse($service->rotateThumbnail(7, 1, 'left'));
    }

    public function testDeleteRemovesRowAndThumbnailFile(): void
    {
        $service = $this->service(['id' => 7, 'thumbnail_path' => $this->thumbPath], $deletedId);

        $this->assertTrue($service->deletePhoto(7, 1));
        $this->assertSame(7, $deletedId);
        $this->assertFileDoesNotExist($this->thumbPath);
    }

    public function testDeleteWithoutThumbnailStillRemovesRow(): void
    {
        $service = $this->service(['id' => 7, 'thumbnail_path' => null], $deletedId);

        $this->assertTrue($service->deletePhoto(7, 1));
        $this->assertSame(7, $deletedId);
    }

    public function testDeleteReturnsFalseWhenNotOwned(): void
    {
        $service = $this->service(null);

        $this->assertFalse($service->deletePhoto(7, 1));
    }
}
