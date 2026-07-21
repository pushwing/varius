<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PhotoLocationModel;

/**
 * 저장된 좌표를 날짜별 동선(마커 + 경로선)으로 조합한다.
 *
 * 지도 프론트(Leaflet)가 그대로 렌더할 수 있는 형태로 가공한다:
 * 날짜별 그룹 + 날짜별 색상 + 각 날짜 내 시간순 좌표.
 */
final class RouteVisualizationService
{
    /**
     * 날짜 그룹에 순환 배정하는 고정 팔레트(서로 구분되는 색).
     *
     * @var list<string>
     */
    private const PALETTE = [
        '#e6194b', '#3cb44b', '#4363d8', '#f58231', '#911eb4', '#46f0f0',
        '#f032e6', '#bcf60c', '#fabebe', '#008080', '#9a6324', '#800000',
    ];

    /** 이 거리(km) 이내 지점은 "같은 장소 연속촬영"으로 묶는다(GPS 오차 감안, 약 30m). */
    private const CLUSTER_RADIUS_KM = 0.03;

    public function __construct(
        private readonly PhotoLocationModel $model,
    ) {
    }

    /**
     * 사용자의 좌표를 날짜별 동선으로 조합한다.
     *
     * points 는 경로선(polyline)용 전체 좌표를 시간순 그대로 담고, clusters 는 같은
     * 장소에서 찍힌 사진들을 마커 하나로 묶어 보여주기 위한 그룹이다.
     *
     * @return array{dates: list<array{
     *     date: string,
     *     color: string,
     *     points: list<array{lat: float, lng: float, taken_at: string, media_item_id: string, thumbnail_url: string|null}>,
     *     clusters: list<array{lat: float, lng: float, photos: list<array{media_item_id: string, taken_at: string, thumbnail_url: string|null}>}>
     * }>}
     */
    public function buildForUser(int $userId): array
    {
        $rows = $this->model->findByUserOrdered($userId);

        // taken_at 오름차순으로 조회되므로 날짜별 그룹·그룹 내 순서가 자연히 유지된다.
        $grouped = [];
        foreach ($rows as $row) {
            $takenAt = (string) ($row['taken_at'] ?? '');
            if ($takenAt === '') {
                continue;
            }

            $date = substr($takenAt, 0, 10);
            $grouped[$date][] = [
                'lat' => (float) ($row['lat'] ?? 0),
                'lng' => (float) ($row['lng'] ?? 0),
                'taken_at' => $takenAt,
                'media_item_id' => (string) ($row['source_item_id'] ?? ''),
                'thumbnail_url' => empty($row['thumbnail_path']) ? null : '/thumbnails/' . (int) ($row['id'] ?? 0),
            ];
        }

        $dates = [];
        $index = 0;
        foreach ($grouped as $date => $points) {
            $dates[] = [
                'date' => $date,
                'color' => self::PALETTE[$index % count(self::PALETTE)],
                'points' => $points,
                'clusters' => $this->clusterByProximity($points),
            ];
            $index++;
        }

        return ['dates' => $dates];
    }

    /**
     * GPS 오차를 감안해 가까운 지점끼리 묶는다(같은 장소 연속촬영). 각 클러스터의
     * 좌표는 그 클러스터의 첫 지점 좌표를 기준으로 삼는다(드리프트 방지).
     *
     * @param list<array{lat: float, lng: float, taken_at: string, media_item_id: string, thumbnail_url: string|null}> $points
     *
     * @return list<array{lat: float, lng: float, photos: list<array{media_item_id: string, taken_at: string, thumbnail_url: string|null}>}>
     */
    private function clusterByProximity(array $points): array
    {
        $clusters = [];

        foreach ($points as $point) {
            $photo = [
                'media_item_id' => $point['media_item_id'],
                'taken_at' => $point['taken_at'],
                'thumbnail_url' => $point['thumbnail_url'],
            ];

            $matched = false;
            foreach ($clusters as &$cluster) {
                $distanceKm = GeoDistanceCalculator::kilometers($cluster['lat'], $cluster['lng'], $point['lat'], $point['lng']);
                if ($distanceKm <= self::CLUSTER_RADIUS_KM) {
                    $cluster['photos'][] = $photo;
                    $matched = true;
                    break;
                }
            }
            unset($cluster);

            if (! $matched) {
                $clusters[] = [
                    'lat' => $point['lat'],
                    'lng' => $point['lng'],
                    'photos' => [$photo],
                ];
            }
        }

        return $clusters;
    }
}
