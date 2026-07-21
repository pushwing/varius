<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PhotoLocationModel;
use App\Support\Filesystem;

/**
 * 업로드 처리 잔존물 정리 서비스(안전망).
 *
 * 정상 경로에서는 TakeoutIngestService 가 임시 디렉터리를, 컨트롤러가 zip 을 즉시 지운다.
 * 하지만 치명적 오류(OOM·타임아웃)로 finally 정리가 건너뛰어지면 압축 해제된 원본이
 * 임시 디렉터리에 잔존할 수 있다. 또한 이상치 필터·저장 실패로 DB 참조 없는 고아 썸네일이
 * 남을 수 있다. 이 서비스는 그런 잔존물을 주기적으로(크론 + iter:cleanup) 걷어낸다.
 */
final class StorageMaintenanceService
{
    public function __construct(
        private readonly PhotoLocationModel $photoLocations,
        private readonly string $uploadsDir,
        private readonly string $thumbnailBaseDir,
    ) {
    }

    /**
     * 지정 기간보다 오래된 업로드 임시물(takeout_* 디렉터리, 잔존 zip)을 삭제한다.
     *
     * @return int 삭제한 항목 수
     */
    public function pruneStaleUploads(int $olderThanSeconds): int
    {
        $threshold = time() - $olderThanSeconds;
        $removed = 0;

        foreach ($this->glob($this->uploadsDir . '/takeout_*') as $dir) {
            if (is_dir($dir) && $this->modifiedBefore($dir, $threshold)) {
                Filesystem::removeDirectory($dir);
                $removed++;
            }
        }

        foreach ($this->glob($this->uploadsDir . '/*.zip') as $zip) {
            if (is_file($zip) && $this->modifiedBefore($zip, $threshold)) {
                unlink($zip);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * DB(photo_locations)에서 참조하지 않는 썸네일 파일을 삭제한다.
     *
     * @return int 삭제한 파일 수
     */
    public function pruneOrphanThumbnails(): int
    {
        $referenced = [];
        foreach ($this->photoLocations->allThumbnailPaths() as $path) {
            $referenced[$path] = true;
        }

        $removed = 0;
        foreach ($this->glob($this->thumbnailBaseDir . '/*') as $userDir) {
            if (! is_dir($userDir)) {
                continue;
            }

            foreach ($this->glob($userDir . '/*') as $file) {
                if (is_file($file) && ! isset($referenced[$file])) {
                    unlink($file);
                    $removed++;
                }
            }
        }

        return $removed;
    }

    private function modifiedBefore(string $path, int $threshold): bool
    {
        $mtime = @filemtime($path);

        return $mtime !== false && $mtime < $threshold;
    }

    /**
     * @return list<string>
     */
    private function glob(string $pattern): array
    {
        $matches = glob($pattern);

        return $matches === false ? [] : $matches;
    }
}
