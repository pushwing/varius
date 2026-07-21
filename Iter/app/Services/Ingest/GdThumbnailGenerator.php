<?php

declare(strict_types=1);

namespace App\Services\Ingest;

use RuntimeException;

/**
 * GD 기반 썸네일 생성기 — 가로 300px 기준으로 비율을 유지해 리사이즈한다.
 * 원본이 목표 폭보다 좁으면 확대하지 않고 그대로 저장한다.
 */
class GdThumbnailGenerator implements ThumbnailGeneratorInterface
{
    private const TARGET_WIDTH = 300;

    public function __construct(
        private readonly string $outputDir,
    ) {
    }

    public function generate(string $sourcePath, string $mediaItemId, int $userId): ?string
    {
        $info = @getimagesize($sourcePath);
        if ($info === false) {
            return null;
        }

        [$srcWidth, $srcHeight, $type] = $info;
        $source = $this->readImage($sourcePath, $type);
        if ($source === null) {
            return null;
        }

        $targetWidth = min(self::TARGET_WIDTH, $srcWidth); // 확대하지 않는다.
        $targetHeight = (int) round($srcHeight * ($targetWidth / $srcWidth));

        $thumb = imagecreatetruecolor($targetWidth, $targetHeight);
        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $srcWidth, $srcHeight);

        $userDir = $this->outputDir . '/' . $userId;
        $this->ensureOutputDir($userDir);
        $path = $userDir . '/' . $this->safeFileName($mediaItemId) . '.jpg';

        $saved = imagejpeg($thumb, $path, 82);

        return $saved ? $path : null;
    }

    /**
     * @return \GdImage|null
     */
    private function readImage(string $path, int $type)
    {
        return match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_GIF => @imagecreatefromgif($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null,
            default => null,
        } ?: null;
    }

    /**
     * media_item_id 를 안전한 파일명으로 정리한다(예약문자 제거).
     */
    private function safeFileName(string $mediaItemId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $mediaItemId);

        return $safe === '' || $safe === null ? 'unknown' : $safe;
    }

    private function ensureOutputDir(string $dir): void
    {
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException('썸네일 디렉토리를 만들 수 없습니다: ' . $dir);
        }
    }
}
