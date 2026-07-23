<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PhotoLocationModel;

/**
 * 발자국 지도 집계 — 방문 국가·국내 시·도별 사진 수와 요약 통계를 조립한다.
 */
final class FootprintService
{
    public function __construct(
        private readonly PhotoLocationModel $photoLocations,
    ) {
    }

    /**
     * @return array{countries: list<array{code: string, photos: int}>, regions: list<array{code: string, photos: int}>, stats: array{countryCount: int, regionCount: int}}
     */
    public function buildForUser(int $userId): array
    {
        $countries = $this->photoLocations->countByCountry($userId);
        $regions = $this->photoLocations->countByRegion($userId);

        return [
            'countries' => $countries,
            'regions' => $regions,
            'stats' => [
                'countryCount' => count($countries),
                'regionCount' => count($regions),
            ],
        ];
    }
}
