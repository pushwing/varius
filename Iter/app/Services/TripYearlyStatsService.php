<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PhotoLocationModel;
use App\Models\TripModel;
use App\Support\TimeConverter;

/**
 * 연간 통계 — 그 해(KST)의 총 여행일수·캘린더 히트맵용 날짜 목록·가장 많이 방문한 지점을
 * 계산한다. "가장 많이 간 도시"는 역지오코딩 인프라가 없어 실제 지명 대신 PointClusterer
 * 좌표 클러스터로 대체한다(Iter/docs/superpowers/specs/2026-07-23-trip-yearly-heatmap-design.md 참고).
 */
final class TripYearlyStatsService
{
    public function __construct(
        private readonly TripModel $trips,
        private readonly PhotoLocationModel $photos,
    ) {
    }

    /**
     * @return array{travel_days: int, heatmap_dates: list<string>, top_spot: array{lat: float, lng: float, visit_count: int, thumbnail_url: string|null}|null}
     */
    public function buildForYear(int $userId, int $year): array
    {
        $travelDates = $this->collectTravelDates($userId, $year);

        return [
            'travel_days' => count($travelDates),
            'heatmap_dates' => $travelDates,
            'top_spot' => $this->findTopSpot($userId, $year),
        ];
    }

    /**
     * 저장된 여행들의 날짜 범위를 그 해로 잘라(clamp) 중복 없는 날짜 목록을 만든다.
     *
     * @return list<string>
     */
    private function collectTravelDates(int $userId, int $year): array
    {
        $yearStart = $year . '-01-01';
        $yearEnd = $year . '-12-31';

        $dates = [];
        foreach ($this->trips->findByUserInYear($userId, $year) as $trip) {
            $start = max((string) $trip['start_date'], $yearStart);
            $end = min((string) $trip['end_date'], $yearEnd);

            $cursor = $start;
            while ($cursor <= $end) {
                $dates[$cursor] = true;
                $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
            }
        }

        $sorted = array_keys($dates);
        sort($sorted);

        return $sorted;
    }

    /**
     * 그 해(KST) 좌표를 클러스터링해 사진 수가 가장 많은 지점을 찾는다.
     *
     * @return array{lat: float, lng: float, visit_count: int, thumbnail_url: string|null}|null
     */
    private function findTopSpot(int $userId, int $year): ?array
    {
        [$startUtc] = TimeConverter::kstDateToUtcRange($year . '-01-01');
        [, $endUtc] = TimeConverter::kstDateToUtcRange($year . '-12-31');
        $rows = $this->photos->findByUserBetween($userId, $startUtc, $endUtc);

        $points = [];
        $photoRows = [];
        foreach ($rows as $row) {
            $lat = $row['lat'] ?? null;
            $lng = $row['lng'] ?? null;
            if ($lat === null || $lng === null) {
                continue; // GPS 없는 사진은 클러스터링 대상에서 제외.
            }

            $points[] = ['lat' => (float) $lat, 'lng' => (float) $lng];
            $photoRows[] = $row;
        }

        if ($points === []) {
            return null;
        }

        $assignments = PointClusterer::assignClusters($points);
        $counts = array_count_values($assignments);
        arsort($counts);
        $topIndex = array_key_first($counts);

        $firstRow = null;
        $visitCount = 0;
        foreach ($assignments as $i => $clusterIndex) {
            if ($clusterIndex !== $topIndex) {
                continue;
            }
            $visitCount++;
            $firstRow ??= $photoRows[$i];
        }

        $thumbnailPath = (string) ($firstRow['thumbnail_path'] ?? '');

        return [
            'lat' => (float) $firstRow['lat'],
            'lng' => (float) $firstRow['lng'],
            'visit_count' => $visitCount,
            'thumbnail_url' => $thumbnailPath !== '' ? '/thumbnails/' . (int) $firstRow['id'] : null,
        ];
    }
}
