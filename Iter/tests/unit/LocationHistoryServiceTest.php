<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\TimelinePointModel;
use App\Services\Ingest\TimelineHistoryParser;
use App\Services\Ingest\TimelineTrackDownsampler;
use App\Services\LocationHistoryService;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

/**
 * 위치기록 인제스트·트랙 조회 조립 검증 — 모델만 Mock(파서는 final 이라 실제 인스턴스 사용).
 */
final class LocationHistoryServiceTest extends CIUnitTestCase
{
    // TimelineHistoryParser 는 final 이라 mock 할 수 없다 — 실제 파서 + 임시 fixture 로 검증한다.
    private function writeTempTimeline(string $json): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'tl');
        file_put_contents($path, $json);

        return $path;
    }

    public function testIngestFileParsesDownsamplesAndSaves(): void
    {
        // 두 번째 포인트는 20초·0m — 다운샘플링으로 스킵된다.
        $path = $this->writeTempTimeline(<<<'JSON'
            {"semanticSegments":[{"timelinePath":[
                {"point":"37.5665000°, 126.9780000°","time":"2026-07-20T09:10:00.000+09:00"},
                {"point":"37.5665000°, 126.9780000°","time":"2026-07-20T09:10:20.000+09:00"},
                {"point":"37.5700000°, 126.9850000°","time":"2026-07-21T15:00:00.000+09:00"}
            ]}]}
            JSON);

        $model = $this->createMock(TimelinePointModel::class);
        $model->method('saveBatch')->willReturnCallback(function (int $userId, array $points): int {
            $this->assertSame(7, $userId);
            $this->assertCount(2, $points); // 다운샘플링 후 2건
            $this->assertSame('2026-07-20 00:10:00', $points[0]->recordedAt); // +09:00 → UTC

            return 1; // 1건은 기존 중복이라 스킵됐다고 가정
        });

        $service = new LocationHistoryService(new TimelineHistoryParser(), new TimelineTrackDownsampler(), $model);

        try {
            $result = $service->ingestFile($path, 7);
        } finally {
            unlink($path);
        }

        $this->assertSame(1, $result['saved']);
        $this->assertSame(1, $result['skipped']); // 저장 대상 2 - 삽입 1
        $this->assertSame(3, $result['totalParsed']);
        $this->assertSame('2026-07-20', $result['firstDate']); // UTC 00:10 → KST 09:10 같은 날
        $this->assertSame('2026-07-21', $result['lastDate']);
    }

    public function testIngestFileRejectsOversizedFile(): void
    {
        $path = $this->writeTempTimeline('{}');

        $model = $this->createMock(TimelinePointModel::class);
        $model->expects($this->never())->method('saveBatch');

        // 상한 1바이트 — 파싱 전에 거부돼야 한다.
        $service = new LocationHistoryService(new TimelineHistoryParser(), new TimelineTrackDownsampler(), $model, 1);

        try {
            $this->expectException(RuntimeException::class);
            $service->ingestFile($path, 7);
        } finally {
            unlink($path);
        }
    }

    public function testTrackForDateConvertsKstDateAndReturnsPairs(): void
    {
        $model = $this->createMock(TimelinePointModel::class);
        $model->method('findTrackByUtcRange')->willReturnCallback(
            function (int $userId, string $from, string $to): array {
                $this->assertSame(7, $userId);
                $this->assertSame('2026-07-19 15:00:00', $from); // KST 2026-07-20 00:00 → UTC
                $this->assertSame('2026-07-20 14:59:59', $to);

                return [['lat' => 37.5665, 'lng' => 126.978]];
            },
        );

        // 파서는 final 이라 mock 불가 — 이 경로에선 호출되지 않으므로 실제 인스턴스를 넣는다.
        $service = new LocationHistoryService(
            new TimelineHistoryParser(),
            new TimelineTrackDownsampler(),
            $model,
        );

        $this->assertSame([[37.5665, 126.978]], $service->trackForDate(7, '2026-07-20'));
    }
}
