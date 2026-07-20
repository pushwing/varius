<?php

declare(strict_types=1);

namespace App\Services\Ingest;

/**
 * 1차 추출기(보통 네이티브 exif_read_data)로 실패하면 2차 추출기(exiftool 등)로 재시도한다.
 *
 * HEIC 등 네이티브가 못 읽는 포맷에 대한 대체 경로를 조합으로 제공한다.
 */
final class FallbackExifExtractor implements ExifExtractorInterface
{
    public function __construct(
        private readonly ExifExtractorInterface $primary,
        private readonly ExifExtractorInterface $secondary,
    ) {
    }

    public function extract(string $filePath): ?ExifLocation
    {
        return $this->primary->extract($filePath) ?? $this->secondary->extract($filePath);
    }
}
