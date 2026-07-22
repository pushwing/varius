<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PhotoLocationModel;
use App\Models\TripModel;
use App\Services\TripSuggestionService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TripSuggestionServiceTest extends CIUnitTestCase
{
    /**
     * @param list<array<string, mixed>> $photoRows
     * @param list<array<string, mixed>> $existingTrips
     */
    private function service(array $photoRows, array $existingTrips = []): TripSuggestionService
    {
        $photoModel = $this->createMock(PhotoLocationModel::class);
        $photoModel->method('findByUserOrdered')->willReturn($photoRows);

        $tripModel = $this->createMock(TripModel::class);
        $tripModel->method('findByUserOrdered')->willReturn($existingTrips);

        return new TripSuggestionService($photoModel, $tripModel);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id, string $takenAtUtc, ?string $thumbnailPath = '/t.jpg'): array
    {
        return ['id' => $id, 'source_item_id' => 'm' . $id, 'lat' => '37.5', 'lng' => '127.0', 'thumbnail_path' => $thumbnailPath, 'taken_at' => $takenAtUtc];
    }

    public function testGroupsConsecutiveDatesIntoOneSuggestion(): void
    {
        $service = $this->service([
            $this->row(1, '2024-03-15 00:00:00'),
            $this->row(2, '2024-03-16 00:00:00'),
            $this->row(3, '2024-03-17 00:00:00'),
        ]);

        $suggestions = $service->suggest(1);

        $this->assertCount(1, $suggestions);
        $this->assertSame('2024-03-15', $suggestions[0]['start_date']);
        $this->assertSame('2024-03-17', $suggestions[0]['end_date']);
        $this->assertSame(3, $suggestions[0]['photo_count']);
        $this->assertSame('3월 15일~17일 여행', $suggestions[0]['suggested_title']);
        $this->assertSame(1, $suggestions[0]['first_photo_id']);
    }

    public function testSplitsWhenGapIsThreeDaysOrMore(): void
    {
        $service = $this->service([
            $this->row(1, '2024-03-15 00:00:00'),
            $this->row(2, '2024-03-18 00:00:00'), // 3일 공백 → 새 그룹.
        ]);

        $suggestions = $service->suggest(1);

        $this->assertCount(2, $suggestions);
        $this->assertSame('2024-03-15', $suggestions[0]['end_date']);
        $this->assertSame('2024-03-18', $suggestions[1]['start_date']);
    }

    public function testKeepsTogetherWhenGapIsUnderThreeDays(): void
    {
        $service = $this->service([
            $this->row(1, '2024-03-15 00:00:00'),
            $this->row(2, '2024-03-17 00:00:00'), // 2일 공백 → 같은 그룹.
        ]);

        $suggestions = $service->suggest(1);

        $this->assertCount(1, $suggestions);
        $this->assertSame('2024-03-15', $suggestions[0]['start_date']);
        $this->assertSame('2024-03-17', $suggestions[0]['end_date']);
    }

    public function testSingleDayGroupUsesSingleDayTitle(): void
    {
        $suggestions = $this->service([$this->row(1, '2024-03-15 00:00:00')])->suggest(1);

        $this->assertSame('3월 15일 여행', $suggestions[0]['suggested_title']);
    }

    public function testExcludesDatesAlreadyCoveredByExistingTrip(): void
    {
        $service = $this->service(
            [
                $this->row(1, '2024-03-15 00:00:00'),
                $this->row(2, '2024-03-16 00:00:00'),
                $this->row(3, '2024-03-20 00:00:00'),
            ],
            [['start_date' => '2024-03-15', 'end_date' => '2024-03-16']],
        );

        $suggestions = $service->suggest(1);

        // 3/15~16 은 이미 저장된 여행 범위라 제외되고, 3/20 만 새 제안으로 남는다.
        $this->assertCount(1, $suggestions);
        $this->assertSame('2024-03-20', $suggestions[0]['start_date']);
    }

    public function testUtcDateBoundaryShiftsToNextKstDate(): void
    {
        // UTC 23:30 은 KST 로 다음 날 — 날짜 그룹핑이 KST 기준이어야 한다.
        $suggestions = $this->service([$this->row(1, '2024-03-15 23:30:00')])->suggest(1);

        $this->assertSame('2024-03-16', $suggestions[0]['start_date']);
    }

    public function testIgnoresPhotosWithoutThumbnailForFirstPhotoIdButCountsThem(): void
    {
        $suggestions = $this->service([
            $this->row(1, '2024-03-15 00:00:00', null),
            $this->row(2, '2024-03-15 01:00:00', '/t.jpg'),
        ])->suggest(1);

        $this->assertSame(2, $suggestions[0]['photo_count']);
        $this->assertSame(2, $suggestions[0]['first_photo_id']);
    }

    public function testEmptyPhotosReturnsEmptySuggestions(): void
    {
        $this->assertSame([], $this->service([])->suggest(1));
    }
}
