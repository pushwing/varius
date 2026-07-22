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
        $photoModel->method('findByUserBetween')->willReturn($photoRows);

        $dayNoteModel = $this->createMock(DayNoteModel::class);
        $dayNoteModel->method('findForDate')->willReturn($dayNote);

        $timeNoteModel = $this->createMock(TimeNoteModel::class);
        $timeNoteModel->method('findForDate')->willReturn($memos);

        return new TimelineService($photoModel, $dayNoteModel, $timeNoteModel);
    }

    public function testGroupsPhotosByKstHourWithRepresentativeCoords(): void
    {
        // 저장은 UTC — 시간 그룹·라벨은 KST(+9) 기준이어야 한다.
        $service = $this->service([
            ['id' => 1, 'source_item_id' => 'm1', 'lat' => '37.5000000', 'lng' => '127.0000000', 'thumbnail_path' => '/t/1.jpg', 'taken_at' => '2024-03-15 09:10:00'],
            ['id' => 2, 'source_item_id' => 'm2', 'lat' => '37.5001000', 'lng' => '127.0001000', 'thumbnail_path' => null, 'taken_at' => '2024-03-15 09:40:00'],
            ['id' => 3, 'source_item_id' => 'm3', 'lat' => '37.6000000', 'lng' => '127.1000000', 'thumbnail_path' => '/t/3.jpg', 'taken_at' => '2024-03-15 12:05:00'],
        ]);

        $result = $service->buildForDate(1, '2024-03-15');

        $this->assertSame('2024-03-15', $result['date']);
        $this->assertCount(2, $result['hours']);

        $six = $result['hours'][0];
        $this->assertSame(18, $six['hour']);
        $this->assertSame('18:00', $six['label']);
        $this->assertCount(2, $six['photos']);
        $this->assertEqualsWithDelta(37.5, $six['lat'], 0.0001);
        $this->assertEqualsWithDelta(127.0, $six['lng'], 0.0001);
        // 썸네일 URL 은 기존 동선 API 와 동일한 규칙(/thumbnails/{id})을 따른다.
        $this->assertSame('/thumbnails/1', $six['photos'][0]['thumbnail_url']);
        // 사진 촬영 시각도 KST 로 표시한다.
        $this->assertSame('2024-03-15 18:10:00', $six['photos'][0]['taken_at']);
        $this->assertNull($six['photos'][1]['thumbnail_url']);

        $this->assertSame(21, $result['hours'][1]['hour']);
        $this->assertCount(1, $result['hours'][1]['photos']);
    }

    public function testQueriesUtcRangeCoveringKstDay(): void
    {
        $photoModel = $this->createMock(PhotoLocationModel::class);
        // KST 2024-03-15 하루는 UTC 2024-03-14 15:00 ~ 2024-03-15 14:59:59 범위로 조회해야 한다.
        $photoModel->expects($this->once())
            ->method('findByUserBetween')
            ->with(1, '2024-03-14 15:00:00', '2024-03-15 14:59:59')
            ->willReturn([]);

        $dayNoteModel = $this->createMock(DayNoteModel::class);
        $dayNoteModel->method('findForDate')->willReturn(null);
        $timeNoteModel = $this->createMock(TimeNoteModel::class);
        $timeNoteModel->method('findForDate')->willReturn([]);

        (new TimelineService($photoModel, $dayNoteModel, $timeNoteModel))->buildForDate(1, '2024-03-15');
    }

    public function testMergesDayNoteAndHourMemos(): void
    {
        $service = $this->service(
            [
                // UTC 09:10 = KST 18:10 → 18시 행.
                ['id' => 1, 'source_item_id' => 'm1', 'lat' => '37.5000000', 'lng' => '127.0000000', 'thumbnail_path' => null, 'taken_at' => '2024-03-15 09:10:00'],
            ],
            ['title' => '서울 1일차', 'body' => '고궁 투어'],
            [18 => '경복궁 산책', 21 => '카페 휴식'],
        );

        $result = $service->buildForDate(1, '2024-03-15');

        $this->assertSame(['title' => '서울 1일차', 'body' => '고궁 투어'], $result['day_note']);

        // 사진이 있는 18시(KST)에는 메모가 붙고, 사진 없는 21시 메모도 별도 시간행으로 나타난다.
        $this->assertCount(2, $result['hours']);
        $this->assertSame('경복궁 산책', $result['hours'][0]['memo']);
        $this->assertSame(21, $result['hours'][1]['hour']);
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
