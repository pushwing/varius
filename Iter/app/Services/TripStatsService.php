<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PhotoLocationModel;
use App\Support\TimeConverter;

/**
 * 여행(날짜 범위)의 이동거리·방문 지점 수 통계 — 여행 상세 페이지가 사용한다.
 */
final class TripStatsService
{
    public function __construct(
        private readonly PhotoLocationModel $photos,
    ) {
    }

    /**
     * 기간(KST) 내 사진을 촬영 순서대로 이어 총 이동거리와 방문 지점 수를 계산한다.
     *
     * @return array{distance_km: float, spot_count: int}
     */
    public function buildStats(int $userId, string $startDate, string $endDate): array
    {
        [$startUtc] = TimeConverter::kstDateToUtcRange($startDate);
        [, $endUtc] = TimeConverter::kstDateToUtcRange($endDate);
        $rows = $this->photos->findByUserBetween($userId, $startUtc, $endUtc);

        $points = [];
        foreach ($rows as $row) {
            $points[] = ['lat' => (float) ($row['lat'] ?? 0), 'lng' => (float) ($row['lng'] ?? 0)];
        }

        $distanceKm = 0.0;
        for ($i = 1, $count = count($points); $i < $count; $i++) {
            $distanceKm += GeoDistanceCalculator::kilometers(
                $points[$i - 1]['lat'],
                $points[$i - 1]['lng'],
                $points[$i]['lat'],
                $points[$i]['lng'],
            );
        }

        return [
            'distance_km' => $distanceKm,
            'spot_count' => PointClusterer::countClusters($points),
        ];
    }
}
