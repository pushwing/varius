<?php

declare(strict_types=1);

namespace App\Services\Ingest;

/**
 * EXIF/사이드카에서 추출한 단일 사진의 위치·시간.
 *
 * lat/lng 는 GPS 정보가 없는 사진도 시간표에 노출하기 위해 nullable 이다(위치는
 * 비워두고 촬영 시각만으로 시간표에 얹는다). takenAt 도 EXIF 에 촬영 시각이 없을
 * 수 있어 nullable('Y-m-d H:i:s' 형식).
 */
final readonly class ExifLocation
{
    public function __construct(
        public ?float $lat,
        public ?float $lng,
        public ?string $takenAt,
    ) {
    }
}
