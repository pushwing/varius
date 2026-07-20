<?php

declare(strict_types=1);

namespace App\Services\Ingest;

/**
 * 동선 한 점 — media_item_id + 좌표 + 촬영 시각.
 *
 * PhotoIngestService 의 출력 단위이며, 후속 저장 단계에서 photo_locations 로 적재된다.
 */
final readonly class PhotoLocation
{
    public function __construct(
        public string $mediaItemId,
        public float $lat,
        public float $lng,
        public string $takenAt,
    ) {
    }

    /**
     * DB 저장·직렬화를 위한 배열 표현(컬럼명 snake_case).
     *
     * @return array{media_item_id: string, lat: float, lng: float, taken_at: string}
     */
    public function toArray(): array
    {
        return [
            'media_item_id' => $this->mediaItemId,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'taken_at' => $this->takenAt,
        ];
    }
}
