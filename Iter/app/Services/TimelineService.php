<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DayNoteModel;
use App\Models\PhotoLocationModel;
use App\Models\TimeNoteModel;
use App\Support\TimeConverter;

/**
 * 하루치 좌표를 시간대별 사진 그룹으로 묶고 날짜 노트·시간대 메모를 병합한다.
 *
 * 시간별 동선 레이어(여행 스케줄 뷰)가 그대로 렌더할 수 있는 형태로 가공한다:
 * 날짜 노트(제목·내용) + 시간행(대표 좌표·사진들·메모).
 */
final class TimelineService
{
    public function __construct(
        private readonly PhotoLocationModel $photoModel,
        private readonly DayNoteModel $dayNoteModel,
        private readonly TimeNoteModel $timeNoteModel,
    ) {
    }

    /**
     * 특정 날짜의 시간별 동선을 조합한다.
     *
     * 사진이 있는 시간대가 기본 행이 되고, 사진 없이 메모만 있는 시간대도
     * 별도 행으로 나타난다(메모가 사진 삭제 후에도 사라지지 않도록).
     *
     * @return array{
     *     date: string,
     *     day_note: array{title: string, body: string}|null,
     *     hours: list<array{
     *         hour: int,
     *         label: string,
     *         lat: float|null,
     *         lng: float|null,
     *         photos: list<array{media_item_id: string, taken_at: string, thumbnail_url: string|null}>,
     *         memo: string|null
     *     }>
     * }
     */
    public function buildForDate(int $userId, string $date): array
    {
        // 날짜는 KST 기준 — 저장(UTC)에서 그 하루를 커버하는 범위로 조회한다.
        [$startUtc, $endUtc] = TimeConverter::kstDateToUtcRange($date);
        $rows = $this->photoModel->findByUserBetween($userId, $startUtc, $endUtc);
        $memos = $this->timeNoteModel->findForDate($userId, $date);

        // taken_at 오름차순으로 조회되므로 시간대 그룹·그룹 내 순서가 자연히 유지된다.
        $grouped = [];
        foreach ($rows as $row) {
            $takenAt = (string) ($row['taken_at'] ?? '');
            if ($takenAt === '') {
                continue;
            }

            // 표시·그룹핑은 한국시간(KST) 기준.
            $takenAt = TimeConverter::utcToKst($takenAt);

            $hour = (int) substr($takenAt, 11, 2);
            if (! isset($grouped[$hour])) {
                $grouped[$hour] = [
                    'lat' => (float) ($row['lat'] ?? 0),
                    'lng' => (float) ($row['lng'] ?? 0),
                    'photos' => [],
                ];
            }

            $grouped[$hour]['photos'][] = [
                'media_item_id' => (string) ($row['source_item_id'] ?? ''),
                'taken_at' => $takenAt,
                'thumbnail_url' => empty($row['thumbnail_path']) ? null : '/thumbnails/' . (int) ($row['id'] ?? 0),
            ];
        }

        // 사진 없는 시간대의 메모도 시간행으로 노출한다.
        foreach (array_keys($memos) as $hour) {
            if (! isset($grouped[$hour])) {
                $grouped[$hour] = ['lat' => null, 'lng' => null, 'photos' => []];
            }
        }

        ksort($grouped);

        $hours = [];
        foreach ($grouped as $hour => $group) {
            $hours[] = [
                'hour' => $hour,
                'label' => sprintf('%02d:00', $hour),
                'lat' => $group['lat'],
                'lng' => $group['lng'],
                'photos' => $group['photos'],
                'memo' => $memos[$hour] ?? null,
            ];
        }

        return [
            'date' => $date,
            'day_note' => $this->dayNoteModel->findForDate($userId, $date),
            'hours' => $hours,
        ];
    }
}
