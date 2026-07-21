<?php

declare(strict_types=1);

namespace App\Services;

/**
 * 두 좌표 간 직선거리(하버사인 공식). TakeoutIngestService(이상치 필터)와
 * RouteVisualizationService(같은 장소 사진 묶기)가 공용으로 사용한다.
 */
final class GeoDistanceCalculator
{
    private const EARTH_RADIUS_KM = 6371.0;

    public static function kilometers(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * asin(min(1.0, sqrt($a)));
    }
}
