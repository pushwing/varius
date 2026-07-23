<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PhotoLocationModel;
use App\Models\TripModel;
use App\Services\TripYearlyStatsService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TripYearlyStatsServiceTest extends CIUnitTestCase
{
    /**
     * @param list<array<string, mixed>> $trips
     * @param list<array<string, mixed>> $photoRows
     */
    private function service(array $trips, array $photoRows = []): TripYearlyStatsService
    {
        $tripModel = $this->createMock(TripModel::class);
        $tripModel->method('findByUserInYear')->willReturn($trips);

        $photoModel = $this->createMock(PhotoLocationModel::class);
        $photoModel->method('findByUserBetween')->willReturn($photoRows);

        return new TripYearlyStatsService($tripModel, $photoModel);
    }

    public function testSumsNonOverlappingTripDates(): void
    {
        $stats = $this->service([
            ['start_date' => '2024-03-15', 'end_date' => '2024-03-17'],
            ['start_date' => '2024-05-01', 'end_date' => '2024-05-01'],
        ])->buildForYear(1, 2024);

        $this->assertSame(4, $stats['travel_days']);
        $this->assertSame(['2024-03-15', '2024-03-16', '2024-03-17', '2024-05-01'], $stats['heatmap_dates']);
    }

    public function testClampsTripDatesCrossingYearBoundary(): void
    {
        $stats = $this->service([
            ['start_date' => '2024-12-30', 'end_date' => '2025-01-02'],
        ])->buildForYear(1, 2024);

        $this->assertSame(2, $stats['travel_days']);
        $this->assertSame(['2024-12-30', '2024-12-31'], $stats['heatmap_dates']);
    }

    public function testReturnsZeroTravelDaysWhenNoTrips(): void
    {
        $stats = $this->service([])->buildForYear(1, 2024);

        $this->assertSame(0, $stats['travel_days']);
        $this->assertSame([], $stats['heatmap_dates']);
    }

    public function testReturnsNullTopSpotWhenNoPhotos(): void
    {
        $stats = $this->service([], [])->buildForYear(1, 2024);

        $this->assertNull($stats['top_spot']);
    }

    public function testReturnsNullTopSpotWhenAllPhotosLackCoordinates(): void
    {
        $stats = $this->service([], [
            ['id' => 1, 'lat' => null, 'lng' => null, 'thumbnail_path' => null, 'taken_at' => '2024-03-15 01:00:00'],
        ])->buildForYear(1, 2024);

        $this->assertNull($stats['top_spot']);
    }

    public function testFindsMostVisitedClusterAsTopSpot(): void
    {
        $stats = $this->service([], [
            // 서울시청 근처 2장(클러스터 A, 약 15m 이내) + 부산 1장(클러스터 B) → A가 최다.
            ['id' => 1, 'lat' => '37.5665', 'lng' => '126.9780', 'thumbnail_path' => '/t/1.jpg', 'taken_at' => '2024-03-15 01:00:00'],
            ['id' => 2, 'lat' => '37.5666', 'lng' => '126.9781', 'thumbnail_path' => null, 'taken_at' => '2024-03-15 02:00:00'],
            ['id' => 3, 'lat' => '35.1796', 'lng' => '129.0756', 'thumbnail_path' => null, 'taken_at' => '2024-03-16 01:00:00'],
        ])->buildForYear(1, 2024);

        $this->assertNotNull($stats['top_spot']);
        $this->assertSame(2, $stats['top_spot']['visit_count']);
        $this->assertEqualsWithDelta(37.5665, $stats['top_spot']['lat'], 0.001);
        $this->assertSame('/thumbnails/1', $stats['top_spot']['thumbnail_url']);
    }
}
