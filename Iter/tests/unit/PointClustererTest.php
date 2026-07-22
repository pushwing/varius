<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PointClusterer;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class PointClustererTest extends CIUnitTestCase
{
    public function testAssignClustersReturnsEmptyArrayForNoPoints(): void
    {
        $this->assertSame([], PointClusterer::assignClusters([]));
    }

    public function testCountClustersReturnsZeroForNoPoints(): void
    {
        $this->assertSame(0, PointClusterer::countClusters([]));
    }

    public function testSinglePointFormsOneCluster(): void
    {
        $points = [['lat' => 37.5665, 'lng' => 126.9780]];

        $this->assertSame([0], PointClusterer::assignClusters($points));
        $this->assertSame(1, PointClusterer::countClusters($points));
    }

    public function testNearbyPointsWithin30MetersMergeIntoOneCluster(): void
    {
        // 위도 0.0002도 차이 ≈ 22m — 같은 지점으로 묶여야 한다.
        $points = [
            ['lat' => 37.50000, 'lng' => 127.00000],
            ['lat' => 37.50020, 'lng' => 127.00000],
        ];

        $this->assertSame([0, 0], PointClusterer::assignClusters($points));
        $this->assertSame(1, PointClusterer::countClusters($points));
    }

    public function testFarApartPointsFormSeparateClusters(): void
    {
        // 위도 0.01도 차이 ≈ 1.1km — 같은 지점으로 보기엔 너무 멀다.
        $points = [
            ['lat' => 37.5000, 'lng' => 127.0000],
            ['lat' => 37.5100, 'lng' => 127.0000],
        ];

        $this->assertSame([0, 1], PointClusterer::assignClusters($points));
        $this->assertSame(2, PointClusterer::countClusters($points));
    }

    public function testThreePointsWhereFirstTwoAreCloseAndThirdIsFar(): void
    {
        $points = [
            ['lat' => 37.50000, 'lng' => 127.00000],
            ['lat' => 37.50020, 'lng' => 127.00000], // ≈22m — 첫 점과 같은 클러스터
            ['lat' => 37.6000, 'lng' => 127.1000],   // 멀리 떨어짐 — 새 클러스터
        ];

        $this->assertSame([0, 0, 1], PointClusterer::assignClusters($points));
        $this->assertSame(2, PointClusterer::countClusters($points));
    }
}
