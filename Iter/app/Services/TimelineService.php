<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DayNoteModel;
use App\Models\PhotoLocationModel;
use App\Models\TimeNoteModel;
use App\Support\TimeConverter;

/**
 * 하루치 좌표를 장소 세그먼트로 묶고 날짜 노트·세그먼트 메모를 병합한다.
 *
 * 시간별 동선 레이어(여행 스케줄 뷰)가 그대로 렌더할 수 있는 형태로 가공한다:
 * 날짜 노트(제목·내용) + 세그먼트 행(시작 시각·대표 좌표·사진들·메모).
 *
 * 세그먼트는 시(hour) 단위 묶음이 아니라 "장소가 바뀌는 지점"에서 나뉜다 —
 * 같은 시간대라도 장소가 다르면(30m 초과 이동) 별도 행이 되어 각자 시각
 * 라벨·주변 업장·메모를 갖는다(촬영 시각이 뭉쳐 있는 데이터 대응).
 */
final class TimelineService
{
    /** 이 거리(km) 이내는 같은 장소로 본다(GPS 오차 감안, 약 30m — 지도 클러스터와 동일). */
    private const SEGMENT_RADIUS_KM = 0.03;

    public function __construct(
        private readonly PhotoLocationModel $photoModel,
        private readonly DayNoteModel $dayNoteModel,
        private readonly TimeNoteModel $timeNoteModel,
    ) {
    }

    /**
     * 특정 날짜(KST)의 시간별 동선을 장소 세그먼트로 조합한다.
     *
     * 사진이 있는 세그먼트가 기본 행이 되고, 사진 없이 메모만 있는 슬롯도
     * 별도 행으로 시각순에 맞게 나타난다(메모가 사진 삭제 후에도 사라지지 않도록).
     *
     * @return array{
     *     date: string,
     *     day_note: array{title: string, body: string}|null,
     *     slots: list<array{
     *         slot: string,
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

        $segments = $this->segmentByPlace($rows);

        // 사진 없는 슬롯의 메모도 행으로 노출한다.
        $photoSlots = array_column($segments, 'slot');
        foreach (array_keys($memos) as $slot) {
            if (! in_array($slot, $photoSlots, true)) {
                $segments[] = ['slot' => $slot, 'lat' => null, 'lng' => null, 'photos' => []];
            }
        }

        usort($segments, static fn (array $a, array $b): int => strcmp($a['slot'], $b['slot']));

        $slots = [];
        foreach ($segments as $segment) {
            $slots[] = [
                'slot' => $segment['slot'],
                'label' => $segment['slot'],
                'lat' => $segment['lat'],
                'lng' => $segment['lng'],
                'photos' => $segment['photos'],
                'memo' => $memos[$segment['slot']] ?? null,
            ];
        }

        return [
            'date' => $date,
            'day_note' => $this->dayNoteModel->findForDate($userId, $date),
            'slots' => $slots,
        ];
    }

    /**
     * 시간순 좌표를 "장소가 바뀌는 지점"에서 잘라 세그먼트로 묶는다.
     *
     * 세그먼트 기준 좌표는 첫 지점 좌표(드리프트 방지), 슬롯 키는 첫 사진의
     * KST 시각("HH:MM")이다.
     *
     * @param list<array<string, mixed>> $rows taken_at(UTC) 오름차순 좌표 행
     *
     * @return list<array{slot: string, lat: float, lng: float, photos: list<array{media_item_id: string, taken_at: string, thumbnail_url: string|null}>}>
     */
    private function segmentByPlace(array $rows): array
    {
        $segments = [];
        $current = null;

        foreach ($rows as $row) {
            $takenAt = (string) ($row['taken_at'] ?? '');
            if ($takenAt === '') {
                continue;
            }

            // 표시·슬롯 키는 한국시간(KST) 기준.
            $takenAt = TimeConverter::utcToKst($takenAt);
            $lat = (float) ($row['lat'] ?? 0);
            $lng = (float) ($row['lng'] ?? 0);

            $photo = [
                'media_item_id' => (string) ($row['source_item_id'] ?? ''),
                'taken_at' => $takenAt,
                'thumbnail_url' => empty($row['thumbnail_path']) ? null : '/thumbnails/' . (int) ($row['id'] ?? 0),
            ];

            $isSamePlace = $current !== null
                && GeoDistanceCalculator::kilometers($current['lat'], $current['lng'], $lat, $lng) <= self::SEGMENT_RADIUS_KM;

            if ($isSamePlace) {
                $current['photos'][] = $photo;
                continue;
            }

            if ($current !== null) {
                $segments[] = $current;
            }

            $current = [
                'slot' => substr($takenAt, 11, 5),
                'lat' => $lat,
                'lng' => $lng,
                'photos' => [$photo],
            ];
        }

        if ($current !== null) {
            $segments[] = $current;
        }

        return $segments;
    }
}
