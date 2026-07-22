<?php

declare(strict_types=1);

namespace App\Services;

/**
 * 좌표 목록을 가까운 지점끼리(GPS 오차 감안 반경) 묶는 순수 유틸리티.
 *
 * RouteVisualizationService(같은 장소 사진 묶기)와 TripStatsService(방문 지점 수 계산)가
 * 공용으로 사용한다 — 반경 기준이 두 곳에서 어긋나지 않도록 판정 로직을 한 곳에만 둔다.
 */
final class PointClusterer
{
    /** 이 거리(km) 이내 지점은 "같은 장소"로 묶는다(GPS 오차 감안, 약 30m). */
    private const CLUSTER_RADIUS_KM = 0.03;

    /**
     * 각 점이 속한 클러스터의 인덱스(0부터 시작)를 points 와 같은 길이의 배열로 반환한다.
     *
     * 클러스터 중심은 그 클러스터의 첫 지점 좌표로 고정한다(드리프트 방지) — 뒤에 들어오는
     * 점이 조금씩 어긋나며 클러스터가 실제 반경보다 넓게 퍼지는 것을 막기 위함이다.
     *
     * @param list<array{lat: float, lng: float}> $points
     *
     * @return list<int>
     */
    public static function assignClusters(array $points): array
    {
        /** @var list<array{lat: float, lng: float}> $centers */
        $centers = [];
        $assignments = [];

        foreach ($points as $point) {
            $matchedIndex = null;
            foreach ($centers as $index => $center) {
                $distanceKm = GeoDistanceCalculator::kilometers($center['lat'], $center['lng'], $point['lat'], $point['lng']);
                if ($distanceKm <= self::CLUSTER_RADIUS_KM) {
                    $matchedIndex = $index;
                    break;
                }
            }

            if ($matchedIndex === null) {
                $centers[] = ['lat' => $point['lat'], 'lng' => $point['lng']];
                $matchedIndex = count($centers) - 1;
            }

            $assignments[] = $matchedIndex;
        }

        return $assignments;
    }

    /**
     * @param list<array{lat: float, lng: float}> $points
     */
    public static function countClusters(array $points): int
    {
        return count(array_unique(self::assignClusters($points)));
    }
}
