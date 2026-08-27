<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\GeocodingService;
use PHPUnit\Framework\TestCase;

final class GeocodingServiceTest extends TestCase
{
    public function testResultsAreNormalizedToPublicLocationShape(): void
    {
        self::assertSame([
            ['display_name' => '파주시청', 'latitude' => 37.7597, 'longitude' => 126.7777],
        ], GeocodingService::normalizeResults([
            ['display_name' => '파주시청', 'lat' => '37.7597', 'lon' => '126.7777', 'importance' => 0.8],
        ]));
    }

    public function testInvalidResultsAreDiscarded(): void
    {
        self::assertSame([], GeocodingService::normalizeResults([
            ['display_name' => '위도 초과', 'lat' => '90.1', 'lon' => '126'],
            ['display_name' => '경도 초과', 'lat' => '37', 'lon' => '-180.1'],
            ['display_name' => '필드 누락', 'lat' => '37'],
            ['display_name' => '좌표 아님', 'lat' => '서울', 'lon' => '126'],
        ]));
    }

    public function testBoundaryCoordinatesAreAccepted(): void
    {
        self::assertCount(1, GeocodingService::normalizeResults([
            ['display_name' => '경계', 'lat' => '-90', 'lon' => '180'],
        ]));
    }
}
