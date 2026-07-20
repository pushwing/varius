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

    public function __construct(
        private readonly PhotoLocationModel $model,
    ) {
    }

    /**
     * 사용자의 좌표를 날짜별 동선으로 조합한다.
     *
     * @return array{dates: list<array{date: string, color: string, points: list<array{lat: float, lng: float, taken_at: string, media_item_id: string}>}>}
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
            ];
        }

        $dates = [];
        $index = 0;
        foreach ($grouped as $date => $points) {
            $dates[] = [
                'date' => $date,
                'color' => self::PALETTE[$index % count(self::PALETTE)],
                'points' => $points,
            ];
            $index++;
        }

        return ['dates' => $dates];
    }
}
