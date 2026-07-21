<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\GeoDistanceCalculator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class GeoDistanceCalculatorTest extends CIUnitTestCase
{
    public function testKilometersReturnsZeroForSameCoordinates(): void
    {
        $this->assertEqualsWithDelta(0.0, GeoDistanceCalculator::kilometers(37.5665, 126.9780, 37.5665, 126.9780), 0.0001);
    }

    public function testKilometersMatchesKnownSeoulBusanDistance(): void
    {
        // 서울시청 ↔ 부산시청 직선거리는 약 325km.
        $km = GeoDistanceCalculator::kilometers(37.5665, 126.9780, 35.1796, 129.0756);

        $this->assertEqualsWithDelta(325.0, $km, 5.0);
    }
}
