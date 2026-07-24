<?php

declare(strict_types=1);

namespace App\Services\Ingest;

/**
 * 위치기록 한 점 — Timeline.json 의 timelinePath 항목 하나.
 *
 * recordedAt 은 UTC 'Y-m-d H:i:s'(프로젝트 저장 표준). KST 변환은 표시 단계에서만 한다.
 */
final readonly class TimelinePoint
{
    public function __construct(
        public float $lat,
        public float $lng,
        public string $recordedAt,
    ) {
    }
}
