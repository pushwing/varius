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

        return $this->buildStatsFromRows($this->photos->findByUserBetween($userId, $startUtc, $endUtc));
    }

    /**
     * 이미 조회된 좌표 행(taken_at 오름차순)으로 이동거리·방문 지점 수를 계산하는 순수
     * 로직. buildStats() 의 실제 계산부이며, TripController::showData() 처럼 사진 조회를
     * 이미 한 번 수행한 호출측이 중복 조회 없이 재사용하기 위해 공개 메서드로 노출한다.
     *
     * @param list<array<string, mixed>> $rows PhotoLocationModel::findByUserBetween() 과
     *                                          같은 형태(id, source_item_id, lat, lng,
     *                                          thumbnail_path, taken_at), taken_at
     *                                          오름차순 정렬이 보장돼야 한다.
     *
     * @return array{distance_km: float, spot_count: int}
     */
    public function buildStatsFromRows(array $rows): array
    {
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
