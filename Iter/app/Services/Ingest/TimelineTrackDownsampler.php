<?php

declare(strict_types=1);

namespace App\Services\Ingest;

use App\Services\GeoDistanceCalculator;

/**
 * 위치기록 다운샘플러 — 정지 구간을 압축해 저장량·렌더 부담을 줄인다.
 *
 * 시간순 정렬 후, 직전 유지점 대비 60초 미만이면서 이동 10m 미만인 포인트를 건너뛴다.
 * 첫 포인트는 항상 유지한다.
 */
final class TimelineTrackDownsampler
{
    private const MIN_INTERVAL_SECONDS = 60;
    private const MIN_DISTANCE_METERS = 10.0;

    /**
     * @param list<TimelinePoint> $points
     *
     * @return list<TimelinePoint>
     */
    public function downsample(array $points): array
    {
        if ($points === []) {
            return [];
        }

        usort($points, static fn (TimelinePoint $a, TimelinePoint $b): int => $a->recordedAt <=> $b->recordedAt);

        $kept = [$points[0]];
        $last = $points[0];
        $count = count($points);

        for ($i = 1; $i < $count; $i++) {
            $current = $points[$i];
            // 두 값 모두 같은 서버 타임존으로 해석되므로 차이는 정확하다(단, 두 시각 사이에
            // DST 전환이 끼면 실제 경과와 최대 1시간까지 어긋날 수 있다 — 60초 임계값 대비 극히 드문 경우).
            $elapsed = strtotime($current->recordedAt) - strtotime($last->recordedAt);
            $meters = GeoDistanceCalculator::kilometers($last->lat, $last->lng, $current->lat, $current->lng) * 1000.0;

            if ($elapsed < self::MIN_INTERVAL_SECONDS && $meters < self::MIN_DISTANCE_METERS) {
                continue;
            }

            $kept[] = $current;
            $last = $current;
        }

        return $kept;
    }
}
