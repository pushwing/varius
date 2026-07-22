<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PhotoLocationModel;
use App\Services\GeoDistanceCalculator;
use App\Services\TripStatsService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TripStatsServiceTest extends CIUnitTestCase
{
    /**
     * @param list<array<string, mixed>> $photoRows
     */
    private function service(array $photoRows): TripStatsService
    {
        $model = $this->createMock(PhotoLocationModel::class);
        $model->method('findByUserBetween')->willReturn($photoRows);

        return new TripStatsService($model);
    }

    public function testReturnsZeroesWhenNoPhotos(): void
    {
        $stats = $this->service([])->buildStats(1, '2024-03-15', '2024-03-16');

        $this->assertSame(0.0, $stats['distance_km']);
        $this->assertSame(0, $stats['spot_count']);
    }

    public function testSinglePhotoHasZeroDistanceAndOneSpot(): void
    {
        $stats = $this->service([
            ['lat' => '37.5665', 'lng' => '126.9780', 'taken_at' => '2024-03-15 01:00:00'],
        ])->buildStats(1, '2024-03-15', '2024-03-16');

        $this->assertSame(0.0, $stats['distance_km']);
        $this->assertSame(1, $stats['spot_count']);
    }

    public function testAccumulatesDistanceInChronologicalOrderAndCountsSpots(): void
    {
        // 서울시청 → 강남역 → 잠실, 세 지점 모두 서로 30m 반경 밖(별개 지점).
        $p1 = ['lat' => 37.5665, 'lng' => 126.9780];
        $p2 = ['lat' => 37.4979, 'lng' => 127.0276];
        $p3 = ['lat' => 37.5133, 'lng' => 127.1000];

        $stats = $this->service([
            ['lat' => (string) $p1['lat'], 'lng' => (string) $p1['lng'], 'taken_at' => '2024-03-15 01:00:00'],
            ['lat' => (string) $p2['lat'], 'lng' => (string) $p2['lng'], 'taken_at' => '2024-03-15 02:00:00'],
            ['lat' => (string) $p3['lat'], 'lng' => (string) $p3['lng'], 'taken_at' => '2024-03-15 03:00:00'],
        ])->buildStats(1, '2024-03-15', '2024-03-16');

        $expectedDistance = GeoDistanceCalculator::kilometers($p1['lat'], $p1['lng'], $p2['lat'], $p2['lng'])
            + GeoDistanceCalculator::kilometers($p2['lat'], $p2['lng'], $p3['lat'], $p3['lng']);

        $this->assertEqualsWithDelta($expectedDistance, $stats['distance_km'], 0.0001);
        $this->assertSame(3, $stats['spot_count']);
    }

    public function testPhotosWithinClusterRadiusCountAsOneSpotWithNearZeroDistance(): void
    {
        // 두 지점 모두 같은 좌표(반경 이내) — 방문 지점은 1곳, 이동거리는 0에 가깝다.
        $stats = $this->service([
            ['lat' => '37.5665', 'lng' => '126.9780', 'taken_at' => '2024-03-15 01:00:00'],
            ['lat' => '37.5665', 'lng' => '126.9780', 'taken_at' => '2024-03-15 02:00:00'],
        ])->buildStats(1, '2024-03-15', '2024-03-16');

        $this->assertSame(0.0, $stats['distance_km']);
        $this->assertSame(1, $stats['spot_count']);
    }
}
