<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\DayNoteModel;
use App\Models\PhotoLocationModel;
use App\Models\TimeNoteModel;
use App\Services\TimelineService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TimelineServiceTest extends CIUnitTestCase
{
    /**
     * 세 모델을 스텁한 서비스.
     *
     * @param list<array<string, mixed>>            $photoRows
     * @param array{title: string, body: string}|null $dayNote
     * @param array<int, string>                    $memos
     */
    private function service(array $photoRows, ?array $dayNote = null, array $memos = []): TimelineService
    {
        $photoModel = $this->createMock(PhotoLocationModel::class);
        $photoModel->method('findByUserAndDate')->willReturn($photoRows);

        $dayNoteModel = $this->createMock(DayNoteModel::class);
        $dayNoteModel->method('findForDate')->willReturn($dayNote);

        $timeNoteModel = $this->createMock(TimeNoteModel::class);
        $timeNoteModel->method('findForDate')->willReturn($memos);

        return new TimelineService($photoModel, $dayNoteModel, $timeNoteModel);
    }

    public function testGroupsPhotosByHourWithRepresentativeCoords(): void
    {
        $service = $this->service([
            ['id' => 1, 'source_item_id' => 'm1', 'lat' => '37.5000000', 'lng' => '127.0000000', 'thumbnail_path' => '/t/1.jpg', 'taken_at' => '2024-03-15 09:10:00'],
            ['id' => 2, 'source_item_id' => 'm2', 'lat' => '37.5001000', 'lng' => '127.0001000', 'thumbnail_path' => null, 'taken_at' => '2024-03-15 09:40:00'],
            ['id' => 3, 'source_item_id' => 'm3', 'lat' => '37.6000000', 'lng' => '127.1000000', 'thumbnail_path' => '/t/3.jpg', 'taken_at' => '2024-03-15 12:05:00'],
        ]);

        $result = $service->buildForDate(1, '2024-03-15');

        $this->assertSame('2024-03-15', $result['date']);
        $this->assertCount(2, $result['hours']);

        $nine = $result['hours'][0];
        $this->assertSame(9, $nine['hour']);
        $this->assertSame('09:00', $nine['label']);
        $this->assertCount(2, $nine['photos']);
        $this->assertEqualsWithDelta(37.5, $nine['lat'], 0.0001);
        $this->assertEqualsWithDelta(127.0, $nine['lng'], 0.0001);
        // 썸네일 URL 은 기존 동선 API 와 동일한 규칙(/thumbnails/{id})을 따른다.
        $this->assertSame('/thumbnails/1', $nine['photos'][0]['thumbnail_url']);
        $this->assertNull($nine['photos'][1]['thumbnail_url']);

        $this->assertSame(12, $result['hours'][1]['hour']);
        $this->assertCount(1, $result['hours'][1]['photos']);
    }

    public function testMergesDayNoteAndHourMemos(): void
    {
        $service = $this->service(
            [
                ['id' => 1, 'source_item_id' => 'm1', 'lat' => '37.5000000', 'lng' => '127.0000000', 'thumbnail_path' => null, 'taken_at' => '2024-03-15 09:10:00'],
            ],
            ['title' => '서울 1일차', 'body' => '고궁 투어'],
            [9 => '경복궁 산책', 15 => '카페 휴식'],
        );

        $result = $service->buildForDate(1, '2024-03-15');

        $this->assertSame(['title' => '서울 1일차', 'body' => '고궁 투어'], $result['day_note']);

        // 사진이 있는 09시에는 메모가 붙고, 사진 없는 15시 메모도 별도 시간행으로 나타난다.
        $this->assertCount(2, $result['hours']);
        $this->assertSame('경복궁 산책', $result['hours'][0]['memo']);
        $this->assertSame(15, $result['hours'][1]['hour']);
        $this->assertSame('카페 휴식', $result['hours'][1]['memo']);
        $this->assertSame([], $result['hours'][1]['photos']);
        $this->assertNull($result['hours'][1]['lat']);
    }

    public function testEmptyDateReturnsNoHoursAndNullNote(): void
    {
        $result = $this->service([])->buildForDate(1, '2024-03-15');

        $this->assertNull($result['day_note']);
        $this->assertSame([], $result['hours']);
    }
}
