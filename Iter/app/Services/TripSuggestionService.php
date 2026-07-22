<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PhotoLocationModel;
use App\Models\TripModel;
use App\Support\TimeConverter;

/**
 * 여행 자동 제안 — 아직 저장된 여행에 속하지 않은 날짜들을 3일 공백 규칙으로 묶는다.
 *
 * 아무것도 저장하지 않는 순수 계산이다. "내 여행" 목록을 열 때마다 새로 계산되고,
 * 사용자가 저장해야 비로소 TripModel 레코드가 된다.
 */
final class TripSuggestionService
{
    /** 직전 날짜와 이 이상 차이 나면 새 여행으로 나눈다. */
    private const GAP_DAYS = 3;

    public function __construct(
        private readonly PhotoLocationModel $photos,
        private readonly TripModel $trips,
    ) {
    }

    /**
     * @return list<array{start_date: string, end_date: string, photo_count: int, suggested_title: string, first_photo_id: int|null}>
     */
    public function suggest(int $userId): array
    {
        $perDate = $this->groupByKstDate($this->photos->findByUserOrdered($userId));

        $covered = [];
        foreach ($this->trips->findByUserOrdered($userId) as $trip) {
            foreach ($this->datesBetween((string) $trip['start_date'], (string) $trip['end_date']) as $date) {
                $covered[$date] = true;
            }
        }

        $dates = array_values(array_diff(array_keys($perDate), array_keys($covered)));
        sort($dates);

        $suggestions = [];
        foreach ($this->groupByGap($dates) as $group) {
            $startDate = $group[0];
            $endDate = $group[count($group) - 1];

            $photoCount = 0;
            $firstPhotoId = null;
            foreach ($group as $date) {
                $photoCount += $perDate[$date]['count'];
                if ($firstPhotoId === null) {
                    $firstPhotoId = $perDate[$date]['first_photo_id'];
                }
            }

            $suggestions[] = [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'photo_count' => $photoCount,
                'suggested_title' => $this->suggestTitle($startDate, $endDate),
                'first_photo_id' => $firstPhotoId,
            ];
        }

        return $suggestions;
    }

    /**
     * @param list<array<string, mixed>> $rows taken_at(UTC) 오름차순 좌표 행
     *
     * @return array<string, array{count: int, first_photo_id: int|null}>
     */
    private function groupByKstDate(array $rows): array
    {
        $perDate = [];
        foreach ($rows as $row) {
            $takenAt = (string) ($row['taken_at'] ?? '');
            if ($takenAt === '') {
                continue;
            }

            $date = substr(TimeConverter::utcToKst($takenAt), 0, 10);
            if (! isset($perDate[$date])) {
                $perDate[$date] = ['count' => 0, 'first_photo_id' => null];
            }

            $perDate[$date]['count']++;

            $path = (string) ($row['thumbnail_path'] ?? '');
            if ($perDate[$date]['first_photo_id'] === null && $path !== '') {
                $perDate[$date]['first_photo_id'] = (int) ($row['id'] ?? 0);
            }
        }

        return $perDate;
    }

    /**
     * @param list<string> $dates 오름차순 정렬된 YYYY-MM-DD 목록
     *
     * @return list<list<string>>
     */
    private function groupByGap(array $dates): array
    {
        $groups = [];
        $current = [];
        $previous = null;

        foreach ($dates as $date) {
            if ($previous !== null && $this->daysBetween($previous, $date) >= self::GAP_DAYS) {
                $groups[] = $current;
                $current = [];
            }
            $current[] = $date;
            $previous = $date;
        }

        if ($current !== []) {
            $groups[] = $current;
        }

        return $groups;
    }

    private function daysBetween(string $earlier, string $later): int
    {
        return (int) round((strtotime($later) - strtotime($earlier)) / 86400);
    }

    /**
     * @return list<string>
     */
    private function datesBetween(string $start, string $end): array
    {
        $dates = [];
        $cursor = strtotime($start);
        $endTs = strtotime($end);

        while ($cursor <= $endTs) {
            $dates[] = date('Y-m-d', $cursor);
            $cursor = strtotime('+1 day', $cursor);
        }

        return $dates;
    }

    private function suggestTitle(string $startDate, string $endDate): string
    {
        $startLabel = $this->formatMonthDay($startDate);
        if ($startDate === $endDate) {
            return $startLabel . ' 여행';
        }

        $startParts = explode('-', $startDate);
        $endParts = explode('-', $endDate);
        $endLabel = $startParts[1] === $endParts[1]
            ? ((int) $endParts[2]) . '일'
            : $this->formatMonthDay($endDate);

        return $startLabel . '~' . $endLabel . ' 여행';
    }

    private function formatMonthDay(string $date): string
    {
        $parts = explode('-', $date);

        return ((int) $parts[1]) . '월 ' . ((int) $parts[2]) . '일';
    }
}
