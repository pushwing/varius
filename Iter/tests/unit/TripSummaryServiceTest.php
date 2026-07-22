<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PhotoLocationModel;
use App\Services\TripSummaryService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TripSummaryServiceTest extends CIUnitTestCase
{
    /**
     * @param list<array<string, mixed>> $photoRows
     */
    private function service(array $photoRows): TripSummaryService
    {
        $model = $this->createMock(PhotoLocationModel::class);
        $model->method('findByUserBetween')->willReturn($photoRows);

        return new TripSummaryService($model);
    }

    public function testGroupsPhotosByKstDateWithThumbnailIds(): void
    {
        $service = $this->service([
            ['id' => 1, 'lat' => '37.5', 'lng' => '127.0', 'thumbnail_path' => '/t/1.jpg', 'taken_at' => '2024-03-15 01:00:00'], // KST 3/15 10:00
            ['id' => 2, 'lat' => '37.5', 'lng' => '127.0', 'thumbnail_path' => null, 'taken_at' => '2024-03-15 02:00:00'],       // KST 3/15 11:00, 썸네일 없음
            ['id' => 3, 'lat' => '37.5', 'lng' => '127.0', 'thumbnail_path' => '/t/3.jpg', 'taken_at' => '2024-03-15 23:30:00'], // KST 3/16 08:30
        ]);

        $days = $service->buildDaySummaries(1, '2024-03-15', '2024-03-16');

        $this->assertCount(2, $days);
        $this->assertSame('2024-03-15', $days[0]['date']);
        $this->assertSame(2, $days[0]['photo_count']);
        $this->assertSame([1], $days[0]['thumbnail_ids']); // 썸네일 없는 사진은 제외.
        $this->assertSame('2024-03-16', $days[1]['date']);
        $this->assertSame(1, $days[1]['photo_count']);
        $this->assertSame([3], $days[1]['thumbnail_ids']);
    }

    public function testCapsThumbnailIdsAtSixPerDay(): void
    {
        $rows = [];
        for ($i = 1; $i <= 8; $i++) {
            $rows[] = ['id' => $i, 'lat' => '37.5', 'lng' => '127.0', 'thumbnail_path' => '/t/' . $i . '.jpg', 'taken_at' => sprintf('2024-03-15 0%d:00:00', $i)];
        }

        $days = $this->service($rows)->buildDaySummaries(1, '2024-03-15', '2024-03-15');

        $this->assertCount(1, $days);
        $this->assertCount(6, $days[0]['thumbnail_ids']);
        $this->assertSame(8, $days[0]['photo_count']); // 개수는 상한 없이 전부 센다.
    }

    public function testEmptyRangeReturnsEmptyList(): void
    {
        $this->assertSame([], $this->service([])->buildDaySummaries(1, '2024-03-15', '2024-03-17'));
    }

    public function testResolveCoverIdTrustsStoredCoverWhenPresent(): void
    {
        $service = $this->service([]);

        $this->assertSame(42, $service->resolveCoverId(42, 1, '2024-03-15', '2024-03-17'));
    }

    public function testResolveCoverIdFallsBackToFirstThumbnailWhenNoneStored(): void
    {
        $model = $this->createMock(PhotoLocationModel::class);
        $model->method('findByUserBetween')->willReturn([]);
        $model->expects($this->once())
            ->method('firstThumbnailBetween')
            ->with(1, $this->stringContains('2024-03-14'), $this->stringContains('2024-03-17'))
            ->willReturn(9);

        $service = new TripSummaryService($model);

        $this->assertSame(9, $service->resolveCoverId(null, 1, '2024-03-15', '2024-03-17'));
    }
}
