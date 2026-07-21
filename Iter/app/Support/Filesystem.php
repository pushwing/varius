<?php

declare(strict_types=1);

namespace App\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * 파일시스템 보조 유틸 — 처리 완료·삭제 요청 시 디렉터리를 통째로 지우는 공통 로직.
 *
 * zip 임시 디렉터리 정리(TakeoutIngestService), 사용자 썸네일 삭제(AccountDeletionService),
 * 잔존물 정리(StorageMaintenanceService)에서 공유한다.
 */
final class Filesystem
{
    /**
     * 디렉터리와 그 하위 내용을 재귀적으로 삭제한다. 존재하지 않으면 아무것도 하지 않는다.
     */
    public static function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
