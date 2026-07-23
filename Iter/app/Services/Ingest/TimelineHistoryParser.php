<?php

declare(strict_types=1);

namespace App\Services\Ingest;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * 기기 내보내기 Timeline.json 파서.
 *
 * semanticSegments[].timelinePath[] 만 사용한다(visit/activity 세그먼트는 MVP 제외).
 * point 는 "37.5665000°, 126.9780000°" 형식 — 도 기호를 제거해 float 로 변환하고,
 * time(오프셋 포함 ISO8601)은 UTC 'Y-m-d H:i:s' 로 변환한다(프로젝트 저장 표준).
 * 형식이 어긋난 항목은 조용히 건너뛴다.
 */
final class TimelineHistoryParser
{
    private const POINT_PATTERN = '/^\s*(-?\d+(?:\.\d+)?)\s*°?\s*,\s*(-?\d+(?:\.\d+)?)\s*°?\s*$/u';

    /**
     * @return list<TimelinePoint>
     */
    public function parse(string $jsonPath): array
    {
        $raw = file_get_contents($jsonPath);
        if ($raw === false) {
            throw new RuntimeException("위치기록 파일을 읽을 수 없습니다: {$jsonPath}");
        }

        try {
            /** @var array{semanticSegments?: list<array{timelinePath?: list<array{point?: string, time?: string}>}>} $data */
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('위치기록 JSON 형식이 올바르지 않습니다.', 0, $e);
        }

        $points = [];
        foreach ($data['semanticSegments'] ?? [] as $segment) {
            foreach ($segment['timelinePath'] ?? [] as $entry) {
                $point = $this->toPoint($entry);
                if ($point !== null) {
                    $points[] = $point;
                }
            }
        }

        return $points;
    }

    /**
     * timelinePath 항목 하나를 DTO 로 변환한다 — 불량이면 null.
     *
     * @param array{point?: string, time?: string} $entry
     */
    private function toPoint(array $entry): ?TimelinePoint
    {
        $pointRaw = $entry['point'] ?? null;
        $timeRaw = $entry['time'] ?? null;
        if (! is_string($pointRaw) || ! is_string($timeRaw)) {
            return null;
        }

        if (preg_match(self::POINT_PATTERN, $pointRaw, $m) !== 1) {
            return null;
        }
        $lat = (float) $m[1];
        $lng = (float) $m[2];
        if (abs($lat) > 90.0 || abs($lng) > 180.0) {
            return null;
        }

        // 오프셋 없는 시각은 서버 타임존으로 암묵 해석돼 값이 슬쩍 틀어진다 — 형식 위반으로 간주해 스킵.
        if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $timeRaw) !== 1) {
            return null;
        }

        try {
            $utc = (new DateTimeImmutable($timeRaw))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }

        return new TimelinePoint($lat, $lng, $utc);
    }
}
