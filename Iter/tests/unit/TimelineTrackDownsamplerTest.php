<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ingest\TimelinePoint;
use App\Services\Ingest\TimelineTrackDownsampler;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * 다운샘플링 검증 — 정지 구간 압축(60초 미만 && 10m 미만 스킵).
 */
final class TimelineTrackDownsamplerTest extends CIUnitTestCase
{
    public function testSkipsNearbyPointsWithinInterval(): void
    {
        // 같은 자리(이동 ≈0m)에서 30초 간격 3개 + 10분 뒤 먼 지점 1개
        $points = [
            new TimelinePoint(37.5665, 126.9780, '2026-07-20 00:00:00'),
            new TimelinePoint(37.56651, 126.97801, '2026-07-20 00:00:30'), // ~1.4m·30s → 스킵
            new TimelinePoint(37.56652, 126.97802, '2026-07-20 00:01:00'), // 유지점 대비 ~2.8m·60s → 유지(60초 경과)
            new TimelinePoint(37.5700, 126.9850, '2026-07-20 00:11:00'),
        ];

        $result = (new TimelineTrackDownsampler())->downsample($points);

        $this->assertCount(3, $result);
        $this->assertSame('2026-07-20 00:00:00', $result[0]->recordedAt);
        $this->assertSame('2026-07-20 00:01:00', $result[1]->recordedAt);
        $this->assertSame('2026-07-20 00:11:00', $result[2]->recordedAt);
    }

    public function testKeepsFastMovementWithinInterval(): void
    {
        // 30초 간격이지만 700m 이동 → 유지
        $points = [
            new TimelinePoint(37.5665, 126.9780, '2026-07-20 00:00:00'),
            new TimelinePoint(37.5728, 126.9780, '2026-07-20 00:00:30'),
        ];

        $this->assertCount(2, (new TimelineTrackDownsampler())->downsample($points));
    }

    public function testSortsByTimeBeforeSampling(): void
    {
        $points = [
            new TimelinePoint(37.5700, 126.9850, '2026-07-20 00:10:00'),
            new TimelinePoint(37.5665, 126.9780, '2026-07-20 00:00:00'),
        ];

        $result = (new TimelineTrackDownsampler())->downsample($points);

        $this->assertSame('2026-07-20 00:00:00', $result[0]->recordedAt);
        $this->assertSame('2026-07-20 00:10:00', $result[1]->recordedAt);
    }

    public function testEmptyInputReturnsEmpty(): void
    {
        $this->assertSame([], (new TimelineTrackDownsampler())->downsample([]));
    }
}
