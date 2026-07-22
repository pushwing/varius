<?php

declare(strict_types=1);

namespace App\Services\Poi;

/**
 * 좌표 주변 업장(식당·카페 등) 조회 추상화 — 외부 API 구현을 테스트에서 격리한다.
 */
interface PoiLookupInterface
{
    /**
     * 좌표 주변의 이름 있는 업장 목록을 반환한다.
     *
     * @return list<array{name: string, category: string}>
     *
     * @throws \RuntimeException 외부 API 호출 실패 시
     */
    public function findNearby(float $lat, float $lng): array;
}
