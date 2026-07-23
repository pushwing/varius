<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TimelinePointModel;
use App\Services\Ingest\TimelineHistoryParser;
use App\Services\Ingest\TimelinePoint;
use App\Services\Ingest\TimelineTrackDownsampler;
use App\Support\TimeConverter;
use RuntimeException;

/**
 * 위치기록(Timeline.json) 유스케이스 — 업로드 인제스트와 날짜별 트랙 조회.
 */
final class LocationHistoryService
{
    private const DEFAULT_MAX_BYTES = 64 * 1024 * 1024;

    public function __construct(
        private readonly TimelineHistoryParser $parser,
        private readonly TimelineTrackDownsampler $downsampler,
        private readonly TimelinePointModel $timelinePoints,
        private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
    ) {
    }

    /**
     * 업로드된 Timeline.json 을 파싱·다운샘플링해 저장한다.
     *
     * @return array{saved: int, skipped: int, totalParsed: int, firstDate: ?string, lastDate: ?string}
     */
    public function ingestFile(string $jsonPath, int $userId): array
    {
        $size = filesize($jsonPath);
        if ($size === false || $size > $this->maxBytes) {
            throw new RuntimeException('위치기록 파일이 너무 큽니다(최대 64MB).');
        }

        $parsed = $this->parser->parse($jsonPath);
        $sampled = $this->downsampler->downsample($parsed);
        $saved = $this->timelinePoints->saveBatch($userId, $sampled);

        return [
            'saved' => $saved,
            'skipped' => count($sampled) - $saved,
            'totalParsed' => count($parsed),
            'firstDate' => $sampled === [] ? null : $this->kstDate($sampled[0]),
            'lastDate' => $sampled === [] ? null : $this->kstDate($sampled[count($sampled) - 1]),
        ];
    }

    /**
     * KST 날짜의 트랙 좌표쌍 목록을 돌려준다(지도 폴리라인용).
     *
     * @return list<array{0: float, 1: float}>
     */
    public function trackForDate(int $userId, string $kstDate): array
    {
        [$fromUtc, $toUtc] = TimeConverter::kstDateToUtcRange($kstDate);
        $rows = $this->timelinePoints->findTrackByUtcRange($userId, $fromUtc, $toUtc);

        return array_map(static fn (array $row): array => [$row['lat'], $row['lng']], $rows);
    }

    /**
     * 포인트의 KST 날짜(YYYY-MM-DD).
     */
    private function kstDate(TimelinePoint $point): string
    {
        return substr(TimeConverter::utcToKst($point->recordedAt), 0, 10);
    }
}
