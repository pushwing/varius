<?php

declare(strict_types=1);

namespace App\Services\Region;

use App\Services\Ingest\PhotoLocation;
use RuntimeException;

/**
 * 좌표 → 지역(국가·국내 시·도) 오프라인 판별.
 *
 * 번들 GeoJSON(bbox 프리체크 + ray casting point-in-polygon)만 사용한다 — 외부 API 없음.
 * 한국 좌표는 세계 경계(110m, 해안 거침)보다 해상도 높은 시·도 경계를 먼저 판별해
 * 해안 사진이 바다로 새는 것을 줄인다. 인제스트·백필에서만 로드된다(요청당 lazy 1회 파싱).
 */
final class RegionResolver
{
    /** @var list<array{iso: string, polygons: list<array{bbox: array{float, float, float, float}, rings: list<list<array{float, float}>>}>}>|null */
    private ?array $countryFeatures = null;

    /** @var list<array{iso: string, polygons: list<array{bbox: array{float, float, float, float}, rings: list<list<array{float, float}>>}>}>|null */
    private ?array $sidoFeatures = null;

    public function __construct(
        private readonly string $worldGeoJsonPath,
        private readonly string $sidoGeoJsonPath,
    ) {
    }

    /**
     * @return array{countryCode: ?string, regionCode: ?string}
     */
    public function resolve(float $lat, float $lng): array
    {
        $sido = $this->matchIso($this->sidoFeatures(), $lat, $lng);
        if ($sido !== null) {
            return ['countryCode' => 'KR', 'regionCode' => $sido];
        }

        return ['countryCode' => $this->matchIso($this->countryFeatures(), $lat, $lng), 'regionCode' => null];
    }

    /**
     * 좌표 있는 항목에 지역 코드를 입힌다(없으면 그대로 통과).
     *
     * @param list<PhotoLocation> $locations
     *
     * @return list<PhotoLocation>
     */
    public function enrichAll(array $locations): array
    {
        return array_map(function (PhotoLocation $location): PhotoLocation {
            if ($location->lat === null || $location->lng === null) {
                return $location;
            }
            $region = $this->resolve($location->lat, $location->lng);

            return $location->withRegion($region['countryCode'], $region['regionCode']);
        }, $locations);
    }

    /**
     * @param list<array{iso: string, polygons: list<array{bbox: array{float, float, float, float}, rings: list<list<array{float, float}>>}>}> $features
     */
    private function matchIso(array $features, float $lat, float $lng): ?string
    {
        foreach ($features as $feature) {
            foreach ($feature['polygons'] as $polygon) {
                [$minLng, $minLat, $maxLng, $maxLat] = $polygon['bbox'];
                if ($lng < $minLng || $lng > $maxLng || $lat < $minLat || $lat > $maxLat) {
                    continue;
                }
                if ($this->pointInPolygon($lat, $lng, $polygon['rings'])) {
                    return $feature['iso'];
                }
            }
        }

        return null;
    }

    /**
     * 외곽 링 안에 있고 구멍(holes) 안에 없으면 true.
     *
     * @param list<list<array{float, float}>> $rings 첫 링=외곽, 나머지=구멍
     */
    private function pointInPolygon(float $lat, float $lng, array $rings): bool
    {
        if ($rings === [] || ! $this->pointInRing($lat, $lng, $rings[0])) {
            return false;
        }
        $ringCount = count($rings);
        for ($i = 1; $i < $ringCount; $i++) {
            if ($this->pointInRing($lat, $lng, $rings[$i])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ray casting — 경도 방향 반직선과 링 변의 교차 횟수 홀짝 판정.
     *
     * @param list<array{float, float}> $ring [lng, lat] 순서(GeoJSON 좌표 규약)
     */
    private function pointInRing(float $lat, float $lng, array $ring): bool
    {
        $inside = false;
        $count = count($ring);
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if (($yi > $lat) !== ($yj > $lat)
                && $lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /**
     * @return list<array{iso: string, polygons: list<array{bbox: array{float, float, float, float}, rings: list<list<array{float, float}>>}>}>
     */
    private function countryFeatures(): array
    {
        return $this->countryFeatures ??= $this->loadFeatures($this->worldGeoJsonPath);
    }

    /**
     * @return list<array{iso: string, polygons: list<array{bbox: array{float, float, float, float}, rings: list<list<array{float, float}>>}>}>
     */
    private function sidoFeatures(): array
    {
        return $this->sidoFeatures ??= $this->loadFeatures($this->sidoGeoJsonPath);
    }

    /**
     * GeoJSON 을 판별용 구조로 정규화한다 — iso 없는 feature(미승인 지역 등)는 건너뛴다.
     *
     * @return list<array{iso: string, polygons: list<array{bbox: array{float, float, float, float}, rings: list<list<array{float, float}>>}>}>
     */
    private function loadFeatures(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("경계 GeoJSON 을 읽을 수 없습니다: {$path}");
        }
        /** @var array{features: list<array{properties: array{iso: ?string}, geometry: array{type: string, coordinates: array<int, mixed>}}>} $geo */
        $geo = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $features = [];
        foreach ($geo['features'] as $feature) {
            $iso = $feature['properties']['iso'] ?? null;
            if ($iso === null) {
                continue;
            }
            $geometry = $feature['geometry'];
            /** @var list<list<list<array{float, float}>>> $multi */
            $multi = $geometry['type'] === 'Polygon' ? [$geometry['coordinates']] : $geometry['coordinates'];

            $polygons = [];
            foreach ($multi as $rings) {
                $polygons[] = ['bbox' => $this->ringBbox($rings[0]), 'rings' => $rings];
            }
            $features[] = ['iso' => $iso, 'polygons' => $polygons];
        }

        return $features;
    }

    /**
     * @param list<array{float, float}> $ring
     *
     * @return array{float, float, float, float} [minLng, minLat, maxLng, maxLat]
     */
    private function ringBbox(array $ring): array
    {
        $minLng = $minLat = PHP_FLOAT_MAX;
        $maxLng = $maxLat = -PHP_FLOAT_MAX;
        foreach ($ring as [$lng, $lat]) {
            $minLng = min($minLng, $lng);
            $minLat = min($minLat, $lat);
            $maxLng = max($maxLng, $lng);
            $maxLat = max($maxLat, $lat);
        }

        return [$minLng, $minLat, $maxLng, $maxLat];
    }
}
