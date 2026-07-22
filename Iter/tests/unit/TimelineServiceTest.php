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
     * @param list<array<string, mixed>>              $photoRows
     * @param array{title: string, body: string}|null $dayNote
     * @param array<string, string>                   $memos
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

    public function testSplitsSameHourPhotosIntoSegmentsByPlace(): void
    {
        // 같은 11시대(KST 20시)라도 장소가 30m 넘게 바뀌면 별도 세그먼트로 나뉜다.
        $service = $this->service([
            // UTC 11:05 = KST 20:05 — 장소 A.
            ['id' => 1, 'source_item_id' => 'm1', 'lat' => '37.5000000', 'lng' => '127.0000000', 'thumbnail_path' => '/t/1.jpg', 'taken_at' => '2024-03-15 11:05:00'],
            // 5분 뒤 같은 장소(약 11m 차이) — 같은 세그먼트.
            ['id' => 2, 'source_item_id' => 'm2', 'lat' => '37.5001000', 'lng' => '127.0000000', 'thumbnail_path' => null, 'taken_at' => '2024-03-15 11:10:00'],
            // 35분 뒤 1km 떨어진 장소 B — 새 세그먼트.
            ['id' => 3, 'source_item_id' => 'm3', 'lat' => '37.5090000', 'lng' => '127.0000000', 'thumbnail_path' => '/t/3.jpg', 'taken_at' => '2024-03-15 11:40:00'],
        ]);

        $result = $service->buildForDate(1, '2024-03-15');

        $this->assertSame('2024-03-15', $result['date']);
        $this->assertCount(2, $result['slots']);

        $first = $result['slots'][0];
        $this->assertSame('20:05', $first['slot']);
        $this->assertSame('20:05', $first['label']);
        $this->assertCount(2, $first['photos']);
        $this->assertEqualsWithDelta(37.5, $first['lat'], 0.0001);
        // 썸네일 URL 은 기존 동선 API 와 동일한 규칙(/thumbnails/{id})을 따른다.
        $this->assertSame('/thumbnails/1', $first['photos'][0]['thumbnail_url']);
        $this->assertSame('2024-03-15 20:05:00', $first['photos'][0]['taken_at']);

        $second = $result['slots'][1];
        $this->assertSame('20:40', $second['slot']);
        $this->assertCount(1, $second['photos']);
        $this->assertEqualsWithDelta(37.509, $second['lat'], 0.0001);
    }

    public function testMergesDayNoteAndSlotMemos(): void
    {
        $service = $this->service(
            [
                // UTC 09:10 = KST 18:10 세그먼트.
                ['id' => 1, 'source_item_id' => 'm1', 'lat' => '37.5000000', 'lng' => '127.0000000', 'thumbnail_path' => null, 'taken_at' => '2024-03-15 09:10:00'],
            ],
            ['title' => '서울 1일차', 'body' => '고궁 투어'],
            ['18:10' => '경복궁 산책', '21:30' => '카페 휴식'],
        );

        $result = $service->buildForDate(1, '2024-03-15');

        $this->assertSame(['title' => '서울 1일차', 'body' => '고궁 투어'], $result['day_note']);

        // 사진 세그먼트(18:10)에 메모가 붙고, 사진 없는 21:30 메모도 별도 행으로 시각순에 맞게 나타난다.
        $this->assertCount(2, $result['slots']);
        $this->assertSame('경복궁 산책', $result['slots'][0]['memo']);
        $this->assertSame('21:30', $result['slots'][1]['slot']);
        $this->assertSame('카페 휴식', $result['slots'][1]['memo']);
        $this->assertSame([], $result['slots'][1]['photos']);
        $this->assertNull($result['slots'][1]['lat']);
    }

    public function testMemoOnlySlotIsOrderedAmongSegments(): void
    {
        $service = $this->service(
            [
                ['id' => 1, 'source_item_id' => 'm1', 'lat' => '37.5000000', 'lng' => '127.0000000', 'thumbnail_path' => null, 'taken_at' => '2024-03-15 02:00:00'], // KST 11:00
                ['id' => 2, 'source_item_id' => 'm2', 'lat' => '37.6000000', 'lng' => '127.1000000', 'thumbnail_path' => null, 'taken_at' => '2024-03-15 05:00:00'], // KST 14:00
            ],
            null,
            ['12:30' => '이동 중'],
        );

        $slots = $service->buildForDate(1, '2024-03-15')['slots'];

        $this->assertSame(['11:00', '12:30', '14:00'], array_column($slots, 'slot'));
    }

    public function testEmptyDateReturnsNoSlotsAndNullNote(): void
    {
        $result = $this->service([])->buildForDate(1, '2024-03-15');

        $this->assertNull($result['day_note']);
        $this->assertSame([], $result['slots']);
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
}
