<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Ingest\ExifExtractorInterface;
use App\Services\Ingest\MediaItemDownloaderInterface;
use App\Services\Ingest\PhotoLocation;
use App\Services\Ingest\ThumbnailGeneratorInterface;

/**
 * 선택된 사진 원본에서 동선 좌표를 추출하는 핵심 서비스.
 *
 * HTTP 요청 컨텍스트에 의존하지 않는 순수 함수형: 입력 mediaItems + access token →
 * 출력 PhotoLocation 배열. 다운로더·추출기를 주입받아 조합하므로 향후 큐 워커로 분리하기 쉽다.
 *
 * 풀사이즈 원본 이미지는 저장하지 않는다 — EXIF 추출 후 임시 파일을 즉시 폐기한다.
 * 썸네일 생성기가 주입되면 폐기 전 지도 미리보기용 썸네일만 예외로 남긴다(Iter/CLAUDE.md 저장 정책).
 */
class PhotoIngestService
{
    /**
     * 지구 반지름(km) — Haversine 거리 계산용.
     */
    private const EARTH_RADIUS_KM = 6371.0;

    public function __construct(
        private readonly MediaItemDownloaderInterface $downloader,
        private readonly ExifExtractorInterface $extractor,
        private readonly float $maxSpeedKmh = 200.0,
        private readonly ?ThumbnailGeneratorInterface $thumbnailGenerator = null,
    ) {
    }

    /**
     * mediaItems 원본을 내려받아 EXIF 좌표를 추출하고 이상치를 걸러 동선 좌표를 만든다.
     *
     * @param list<array<string, mixed>> $mediaItems 각 항목은 최소 id·baseUrl 포함
     *
     * @return list<PhotoLocation>
     */
    public function ingest(array $mediaItems, string $accessToken): array
    {
        $paths = $this->downloader->download($mediaItems, $accessToken);

        $locations = [];
        foreach ($mediaItems as $item) {
            $id = isset($item['id']) ? (string) $item['id'] : '';
            if ($id === '' || ! isset($paths[$id])) {
                continue;
            }

            $path = $paths[$id];
            $thumbnailPath = null;

            try {
                $exifLocation = $this->extractor->extract($path);
                if ($exifLocation !== null && $this->thumbnailGenerator !== null) {
                    // 풀사이즈 원본을 폐기하기 전, 존재하는 동안 썸네일을 생성한다.
                    $thumbnailPath = $this->thumbnailGenerator->generate($path, $id);
                }
            } finally {
                // 풀사이즈 원본은 보관하지 않는다 — 추출 성패와 무관하게 임시 파일 폐기.
                if (is_file($path)) {
                    unlink($path);
                }
            }

            if ($exifLocation === null) {
                continue; // GPS 없는 사진은 제외
            }

            $takenAt = $exifLocation->takenAt ?? $this->creationTime($item);
            if ($takenAt === null) {
                continue; // 촬영 시각을 알 수 없으면 동선에 배치 불가
            }

            $locations[] = new PhotoLocation($id, $exifLocation->lat, $exifLocation->lng, $takenAt, $thumbnailPath);
        }

        return $this->filterOutliers($locations);
    }

    /**
     * 직전 유효 지점 대비 비현실적 이동 속도(기본 200km/h 초과)인 지점을 제외한다.
     *
     * @param list<PhotoLocation> $locations
     *
     * @return list<PhotoLocation>
     */
    public function filterOutliers(array $locations): array
    {
        if (count($locations) <= 1) {
            return $locations;
        }

        // 시간순 정렬 후 인접 지점 속도로 판정한다.
        usort($locations, static fn (PhotoLocation $a, PhotoLocation $b): int => strcmp($a->takenAt, $b->takenAt));

        $kept = [$locations[0]];
        $previous = $locations[0];

        foreach (array_slice($locations, 1) as $current) {
            if ($this->isReachable($previous, $current)) {
                $kept[] = $current;
                $previous = $current;
            }
        }

        return $kept;
    }

    /**
     * 두 지점 사이 이동이 속도 임계값 안에서 가능한지 판정한다.
     */
    private function isReachable(PhotoLocation $from, PhotoLocation $to): bool
    {
        $hours = (strtotime($to->takenAt) - strtotime($from->takenAt)) / 3600;
        $distanceKm = $this->haversineKm($from->lat, $from->lng, $to->lat, $to->lng);

        if ($hours <= 0.0) {
            // 같은(또는 역전된) 시각인데 위치가 이동했다면 도달 불가로 본다.
            return $distanceKm < 0.001;
        }

        return ($distanceKm / $hours) <= $this->maxSpeedKmh;
    }

    /**
     * 두 좌표 사이 대권 거리(km).
     */
    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * asin(min(1.0, sqrt($a)));
    }

    /**
     * mediaItem.mediaMetadata.creationTime(RFC3339) 을 "Y-m-d H:i:s" 로 변환한다. 없으면 null.
     *
     * @param array<string, mixed> $item
     */
    private function creationTime(array $item): ?string
    {
        $metadata = $item['mediaMetadata'] ?? null;
        $creation = is_array($metadata) ? ($metadata['creationTime'] ?? null) : null;

        if (! is_string($creation) || $creation === '') {
            return null;
        }

        $timestamp = strtotime($creation);

        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }
}
