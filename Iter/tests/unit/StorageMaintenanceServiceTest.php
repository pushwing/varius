<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PhotoLocationModel;
use App\Services\StorageMaintenanceService;
use App\Support\Filesystem;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class StorageMaintenanceServiceTest extends CIUnitTestCase
{
    private string $uploadsDir;
    private string $thumbnailsDir;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir() . '/iter_maint_' . bin2hex(random_bytes(4));
        $this->uploadsDir = $base . '/uploads';
        $this->thumbnailsDir = $this->uploadsDir . '/thumbnails';
        mkdir($this->thumbnailsDir, 0755, true);
    }

    protected function tearDown(): void
    {
        Filesystem::removeDirectory(dirname($this->uploadsDir));
        parent::tearDown();
    }

    private function service(?PhotoLocationModel $model = null): StorageMaintenanceService
    {
        return new StorageMaintenanceService(
            $model ?? $this->createMock(PhotoLocationModel::class),
            $this->uploadsDir,
            $this->thumbnailsDir,
        );
    }

    public function testPruneStaleUploadsRemovesOldTakeoutDirsAndZipsButKeepsRecentOnes(): void
    {
        $old = time() - 7200; // 2시간 전

        $oldDir = $this->uploadsDir . '/takeout_old';
        mkdir($oldDir, 0755, true);
        file_put_contents($oldDir . '/photo.jpg', 'x');
        touch($oldDir, $old);

        $newDir = $this->uploadsDir . '/takeout_new';
        mkdir($newDir, 0755, true);

        $oldZip = $this->uploadsDir . '/stale.zip';
        file_put_contents($oldZip, 'x');
        touch($oldZip, $old);

        $newZip = $this->uploadsDir . '/fresh.zip';
        file_put_contents($newZip, 'x');

        // 1시간(3600초)보다 오래된 것만 삭제.
        $removed = $this->service()->pruneStaleUploads(3600);

        $this->assertSame(2, $removed);
        $this->assertDirectoryDoesNotExist($oldDir);
        $this->assertFileDoesNotExist($oldZip);
        $this->assertDirectoryExists($newDir);
        $this->assertFileExists($newZip);
    }

    public function testPruneOrphanThumbnailsRemovesFilesNotReferencedInDb(): void
    {
        $userDir = $this->thumbnailsDir . '/7';
        mkdir($userDir, 0755, true);

        $referenced = $userDir . '/kept.jpg';
        $orphan = $userDir . '/orphan.jpg';
        file_put_contents($referenced, 'x');
        file_put_contents($orphan, 'x');

        $model = $this->createMock(PhotoLocationModel::class);
        $model->method('allThumbnailPaths')->willReturn([$referenced]);

        $removed = $this->service($model)->pruneOrphanThumbnails();

        $this->assertSame(1, $removed);
        $this->assertFileExists($referenced);
        $this->assertFileDoesNotExist($orphan);
    }
}
