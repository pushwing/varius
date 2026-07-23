<?php

declare(strict_types=1);

namespace App\Services\Ingest;

/**
 * 동선 한 점 — media_item_id + 좌표(있으면) + 촬영 시각 + (있으면) 썸네일 경로.
 *
 * TakeoutIngestService/PlainZipIngestService 의 출력 단위이며, 후속 저장 단계에서
 * photo_locations 로 적재된다. lat/lng 는 GPS 없이 촬영 시각만 있는 사진도 시간표에
 * 노출하기 위해 nullable 이다(지도·이동거리 통계에서는 좌표 있는 사진만 사용한다).
 * 썸네일은 지도 미리보기 표시용 예외 보관 대상이다(Iter/CLAUDE.md 저장 정책).
 */
final readonly class PhotoLocation
{
    public function __construct(
        public string $mediaItemId,
        public ?float $lat,
        public ?float $lng,
        public string $takenAt,
        public ?string $thumbnailPath = null,
        public ?string $countryCode = null,
        public ?string $regionCode = null,
    ) {
    }

    /**
     * DB 저장·직렬화를 위한 배열 표현(컬럼명 snake_case).
     *
     * @return array{media_item_id: string, lat: float|null, lng: float|null, taken_at: string, thumbnail_path: string|null}
     */
    public function toArray(): array
    {
        return [
            'media_item_id' => $this->mediaItemId,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'taken_at' => $this->takenAt,
            'thumbnail_path' => $this->thumbnailPath,
        ];
    }

    /**
     * 지역 판별 결과를 입힌 사본을 돌려준다(readonly 라 새 인스턴스).
     */
    public function withRegion(?string $countryCode, ?string $regionCode): self
    {
        return new self(
            $this->mediaItemId,
            $this->lat,
            $this->lng,
            $this->takenAt,
            $this->thumbnailPath,
            $countryCode,
            $regionCode,
        );
    }
}
