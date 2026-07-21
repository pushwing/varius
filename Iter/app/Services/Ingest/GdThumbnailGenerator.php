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

    public function generate(string $sourcePath, string $mediaItemId): ?string
    {
        $info = @getimagesize($sourcePath);
        if ($info === false) {
            return null;
        }

        [, , $type] = $info;
        $source = $this->readImage($sourcePath, $type);
        if ($source === null) {
            return null;
        }

        $source = $this->applyExifOrientation($source, $sourcePath, $type);
        $srcWidth = imagesx($source);
        $srcHeight = imagesy($source);

        $targetWidth = min(self::TARGET_WIDTH, $srcWidth); // 확대하지 않는다.
        $targetHeight = (int) round($srcHeight * ($targetWidth / $srcWidth));

        $thumb = imagecreatetruecolor($targetWidth, $targetHeight);
        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $srcWidth, $srcHeight);

        $this->ensureOutputDir();
        $path = $this->outputDir . '/' . $this->safeFileName($mediaItemId) . '.jpg';

        $saved = imagejpeg($thumb, $path, 82);

        return $saved ? $path : null;
    }

    /**
     * EXIF Orientation 태그에 맞춰 회전을 보정한다(휴대폰 사진은 픽셀은 그대로 두고
     * 태그로만 회전 방향을 표기하는 경우가 많아, 보정하지 않으면 썸네일이 옆으로 눕거나
     * 뒤집혀 보인다). 카메라·폰이 실제로 내보내는 1·3·6·8 만 다룬다(2·4·5·7 은 미러링
     * 조합으로 실사용 사례가 사실상 없어 범위에서 제외).
     */
    private function applyExifOrientation(\GdImage $image, string $sourcePath, int $type): \GdImage
    {
        if ($type !== IMAGETYPE_JPEG) {
            return $image;
        }

        $exif = @exif_read_data($sourcePath);
        $orientation = is_array($exif) && is_numeric($exif['Orientation'] ?? null) ? (int) $exif['Orientation'] : 1;

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated instanceof \GdImage ? $rotated : $image;
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

    private function ensureOutputDir(): void
    {
        if (! is_dir($this->outputDir) && ! mkdir($this->outputDir, 0755, true) && ! is_dir($this->outputDir)) {
            throw new RuntimeException('썸네일 디렉토리를 만들 수 없습니다: ' . $this->outputDir);
        }
    }
}
