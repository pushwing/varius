<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DayNoteModel;
use App\Models\PhotoLocationModel;
use App\Models\TimeNoteModel;
use App\Models\UserModel;
use App\Services\Ingest\PhotoLocation;
use App\Services\Poi\PoiLookupInterface;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use RuntimeException;

/**
 * @internal
 */
final class TimelineControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $refresh = true;
    protected $namespace = 'App';

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (new UserModel())->upsertByGoogleSub('sub-timeline', 'timeline@example.com', 'Timeline');
    }

    protected function tearDown(): void
    {
        Services::reset(true); // injectMock(poiLookup) 잔존 방지
        parent::tearDown();
    }

    // ── GET /timeline/{date} ─────────────────────────────────────────

    public function testTimelineRequiresLogin(): void
    {
        $result = $this->get('timeline/2024-03-15');

        $result->assertStatus(401);
    }

    public function testTimelineRejectsInvalidDate(): void
    {
        $result = $this->withSession(['user_id' => $this->userId])->get('timeline/2024-13-99');

        $result->assertStatus(422);
    }

    public function testTimelineReturnsKstHoursWithNotes(): void
    {
        // 저장은 UTC — KST(+9) 기준으로 시간대가 표시된다. UTC 09:10 → KST 18시 행.
        (new PhotoLocationModel())->saveMany($this->userId, [
            new PhotoLocation('t1', 37.5, 127.0, '2024-03-15 09:10:00'),
            new PhotoLocation('t2', 37.5001, 127.0001, '2024-03-15 09:40:00'),
            new PhotoLocation('t3', 37.6, 127.1, '2024-03-15 12:05:00'),
            new PhotoLocation('other-day', 35.1, 129.0, '2024-03-16 08:00:00'),
        ]);
        (new DayNoteModel())->upsertNote($this->userId, '2024-03-15', '서울 1일차', '고궁 투어');
        (new TimeNoteModel())->upsertNote($this->userId, '2024-03-15', 18, '경복궁 산책');

        $result = $this->withSession(['user_id' => $this->userId])->get('timeline/2024-03-15');

        $result->assertStatus(200);
        $data = json_decode($result->getJSON() ?? '', true);
        $this->assertIsArray($data);
        $this->assertSame('2024-03-15', $data['date']);
        $this->assertSame('서울 1일차', $data['day_note']['title']);
        $this->assertCount(2, $data['hours']);
        $this->assertSame(18, $data['hours'][0]['hour']);
        $this->assertCount(2, $data['hours'][0]['photos']);
        $this->assertSame('경복궁 산책', $data['hours'][0]['memo']);
        $this->assertSame(21, $data['hours'][1]['hour']);
        $this->assertNull($data['hours'][1]['memo']);
    }

    // ── POST /timeline/day-note ──────────────────────────────────────

    public function testSaveDayNoteRequiresLogin(): void
    {
        $result = $this->post('timeline/day-note', ['date' => '2024-03-15', 'title' => 't', 'body' => 'b']);

        $result->assertStatus(401);
    }

    public function testSaveDayNoteRejectsInvalidDate(): void
    {
        $result = $this->withSession(['user_id' => $this->userId])
            ->post('timeline/day-note', ['date' => 'nope', 'title' => 't', 'body' => 'b']);

        $result->assertStatus(422);
    }

    public function testSaveDayNoteRejectsTooLongTitle(): void
    {
        $result = $this->withSession(['user_id' => $this->userId])
            ->post('timeline/day-note', ['date' => '2024-03-15', 'title' => str_repeat('가', 101), 'body' => '']);

        $result->assertStatus(422);
    }

    public function testSaveDayNotePersists(): void
    {
        $result = $this->withSession(['user_id' => $this->userId])
            ->post('timeline/day-note', ['date' => '2024-03-15', 'title' => '서울 1일차', 'body' => '고궁 투어']);

        $result->assertStatus(200);
        $this->seeInDatabase('day_notes', [
            'user_id' => $this->userId,
            'note_date' => '2024-03-15',
            'title' => '서울 1일차',
        ]);
    }

    // ── POST /timeline/time-note ─────────────────────────────────────

    public function testSaveTimeNoteRequiresLogin(): void
    {
        $result = $this->post('timeline/time-note', ['date' => '2024-03-15', 'hour' => '9', 'memo' => 'm']);

        $result->assertStatus(401);
    }

    public function testSaveTimeNoteRejectsInvalidHour(): void
    {
        $result = $this->withSession(['user_id' => $this->userId])
            ->post('timeline/time-note', ['date' => '2024-03-15', 'hour' => '24', 'memo' => 'm']);

        $result->assertStatus(422);
    }

    public function testSaveTimeNotePersists(): void
    {
        $result = $this->withSession(['user_id' => $this->userId])
            ->post('timeline/time-note', ['date' => '2024-03-15', 'hour' => '9', 'memo' => '경복궁 산책']);

        $result->assertStatus(200);
        $this->seeInDatabase('time_notes', [
            'user_id' => $this->userId,
            'note_date' => '2024-03-15',
            'hour' => 9,
            'memo' => '경복궁 산책',
        ]);
    }

    // ── GET /timeline/poi ────────────────────────────────────────────

    public function testPoiRequiresLogin(): void
    {
        $result = $this->get('timeline/poi?lat=37.5&lng=127.0');

        $result->assertStatus(401);
    }

    public function testPoiRejectsMissingCoords(): void
    {
        $result = $this->withSession(['user_id' => $this->userId])->get('timeline/poi');

        $result->assertStatus(422);
    }

    public function testPoiReturnsPlacesFromLookup(): void
    {
        $lookup = $this->createMock(PoiLookupInterface::class);
        $lookup->method('findNearby')->willReturn([
            ['name' => '소문난 삼계탕', 'category' => 'restaurant'],
        ]);
        Services::injectMock('poiLookup', $lookup);

        $result = $this->withSession(['user_id' => $this->userId])->get('timeline/poi?lat=37.5&lng=127.0');

        $result->assertStatus(200);
        $data = json_decode($result->getJSON() ?? '', true);
        $this->assertSame('소문난 삼계탕', $data['places'][0]['name']);
    }

    public function testPoiReturns502WhenLookupFails(): void
    {
        $lookup = $this->createMock(PoiLookupInterface::class);
        $lookup->method('findNearby')->willThrowException(new RuntimeException('down'));
        Services::injectMock('poiLookup', $lookup);

        $result = $this->withSession(['user_id' => $this->userId])->get('timeline/poi?lat=37.5&lng=127.0');

        $result->assertStatus(502);
    }
}
