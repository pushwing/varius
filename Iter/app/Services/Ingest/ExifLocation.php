<?php

declare(strict_types=1);

namespace App\Services\Ingest;

/**
 * EXIF 에서 추출한 단일 사진의 위치·시간.
 *
 * takenAt 은 EXIF 에 촬영 시각이 없을 수 있어 nullable('Y-m-d H:i:s' 형식).
 */
final readonly class ExifLocation
{
    public function __construct(
        public float $lat,
        public float $lng,
        public ?string $takenAt,
    ) {
    }
}
