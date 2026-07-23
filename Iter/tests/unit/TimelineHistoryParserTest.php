<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ingest\TimelineHistoryParser;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

/**
 * Timeline.json 파서 검증 — 도 기호 좌표·타임존 변환·불량 항목 스킵.
 */
final class TimelineHistoryParserTest extends CIUnitTestCase
{
    private function fixturePath(): string
    {
        return TESTPATH . '_support/fixtures/timeline-sample.json';
    }

    public function testParsesTimelinePathPointsAndConvertsToUtc(): void
    {
        $points = (new TimelineHistoryParser())->parse($this->fixturePath());

        // 유효 3건만: 불량 좌표·시각 누락·위도 범위 초과·visit-only 세그먼트는 제외
        $this->assertCount(3, $points);

        $this->assertSame(37.5665, $points[0]->lat);
        $this->assertSame(126.978, $points[0]->lng);
        // +09:00 → UTC 변환: 09:10 KST = 00:10 UTC
        $this->assertSame('2026-07-20 00:10:00', $points[0]->recordedAt);
        $this->assertSame('2026-07-20 00:20:00', $points[1]->recordedAt);
        $this->assertSame('2026-07-20 06:30:00', $points[2]->recordedAt);
    }

    public function testMissingSemanticSegmentsReturnsEmpty(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tl');
        file_put_contents($path, '{"rawSignals": []}');

        try {
            $this->assertSame([], (new TimelineHistoryParser())->parse($path));
        } finally {
            unlink($path);
        }
    }

    public function testInvalidJsonThrows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tl');
        file_put_contents($path, '{잘못된 json');

        try {
            $this->expectException(RuntimeException::class);
            (new TimelineHistoryParser())->parse($path);
        } finally {
            unlink($path);
        }
    }
}
