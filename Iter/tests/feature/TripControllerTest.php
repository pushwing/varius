<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PhotoLocationModel;
use App\Models\TripModel;
use App\Models\UserModel;
use App\Services\Ingest\PhotoLocation;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class TripControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $refresh = true;
    protected $namespace = 'App';

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (new UserModel())->upsertByGoogleSub('sub-tripc', 'tripc@example.com', 'TripC');
    }

    // ── GET /trips ────────────────────────────────────────────────────

    public function testIndexRedirectsWhenNotLoggedIn(): void
    {
        $result = $this->get('trips');

        $result->assertRedirect();
    }

    public function testIndexRendersPageWithNavAndDataUrl(): void
    {
        $result = $this->withSession(['user_id' => $this->userId])->get('trips');

        $result->assertStatus(200);
        $body = html_entity_decode((string) $result->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringContainsString('내 여행', $body);
        $this->assertStringContainsString('data-trips-url', $body);
    }

    // ── GET /trips/data ──────────────────────────────────────────────

    public function testDataRequiresLogin(): void
    {
        $result = $this->get('trips/data');

        $result->assertStatus(401);
    }

    public function testDataReturnsSavedTripsWithPhotoCountAndCover(): void
    {
        $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_') . '.jpg';
        file_put_contents($thumbPath, 'bytes');
        (new PhotoLocationModel())->saveMany($this->userId, [
            new PhotoLocation('d1', 37.5, 127.0, '2024-03-15 02:00:00', $thumbPath),
            new PhotoLocation('d2', 37.5, 127.0, '2024-03-16 02:00:00'),
        ]);
        (new TripModel())->insert([
            'user_id' => $this->userId, 'title' => '서울 여행', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-16', 'cover_photo_id' => null,
        ]);

        try {
            $result = $this->withSession(['user_id' => $this->userId])->get('trips/data');

            $result->assertStatus(200);
            $data = json_decode($result->getJSON() ?? '', true);
            $this->assertCount(1, $data['trips']);
            $this->assertSame('서울 여행', $data['trips'][0]['title']);
            $this->assertSame(2, $data['trips'][0]['photo_count']);
            $this->assertNotNull($data['trips'][0]['cover_thumbnail_url']);
        } finally {
            if (is_file($thumbPath)) {
                unlink($thumbPath);
            }
        }
    }

    public function testDataReturnsSuggestionsExcludingSavedTripDates(): void
    {
        (new PhotoLocationModel())->saveMany($this->userId, [
            new PhotoLocation('s1', 37.5, 127.0, '2024-03-15 02:00:00'), // 저장된 여행에 포함.
            new PhotoLocation('s2', 37.5, 127.0, '2024-03-25 02:00:00'), // 미포함 → 제안 대상.
        ]);
        (new TripModel())->insert([
            'user_id' => $this->userId, 'title' => '서울 여행', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-15', 'cover_photo_id' => null,
        ]);

        $result = $this->withSession(['user_id' => $this->userId])->get('trips/data');

        $data = json_decode($result->getJSON() ?? '', true);
        $this->assertCount(1, $data['suggestions']);
        $this->assertSame('2024-03-25', $data['suggestions'][0]['start_date']);
    }

    // ── POST /trips ──────────────────────────────────────────────────

    public function testCreateRequiresLogin(): void
    {
        $result = $this->post('trips', ['title' => 'T', 'body' => '', 'start_date' => '2024-03-15', 'end_date' => '2024-03-16']);

        $result->assertStatus(401);
    }

    public function testCreateRejectsInvalidDateRange(): void
    {
        $result = $this->withSession(['user_id' => $this->userId])
            ->post('trips', ['title' => 'T', 'body' => '', 'start_date' => '2024-03-17', 'end_date' => '2024-03-15']);

        $result->assertStatus(422);
    }

    public function testCreateRejectsSpanOverSixtyDays(): void
    {
        $result = $this->withSession(['user_id' => $this->userId])
            ->post('trips', ['title' => 'T', 'body' => '', 'start_date' => '2024-01-01', 'end_date' => '2024-03-15']);

        $result->assertStatus(422);
    }

    public function testCreateRejectsOverlappingTrip(): void
    {
        (new TripModel())->insert([
            'user_id' => $this->userId, 'title' => '기존 여행', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-17', 'cover_photo_id' => null,
        ]);

        $result = $this->withSession(['user_id' => $this->userId])
            ->post('trips', ['title' => 'T', 'body' => '', 'start_date' => '2024-03-16', 'end_date' => '2024-03-20']);

        $result->assertStatus(422);
    }

    public function testCreateRejectsCoverPhotoOutsideRange(): void
    {
        (new PhotoLocationModel())->saveMany($this->userId, [
            new PhotoLocation('c1', 37.5, 127.0, '2024-03-25 02:00:00'),
        ]);
        $photoId = (int) (new PhotoLocationModel())->where('source_item_id', 'c1')->first()['id'];

        $result = $this->withSession(['user_id' => $this->userId])
            ->post('trips', [
                'title' => 'T', 'body' => '', 'start_date' => '2024-03-15', 'end_date' => '2024-03-16',
                'cover_photo_id' => (string) $photoId,
            ]);

        $result->assertStatus(422);
    }

    public function testCreatePersistsTrip(): void
    {
        $result = $this->withSession(['user_id' => $this->userId])
            ->post('trips', ['title' => '서울 여행', 'body' => '고궁 투어', 'start_date' => '2024-03-15', 'end_date' => '2024-03-16']);

        $result->assertStatus(200);
        $this->seeInDatabase('trips', ['user_id' => $this->userId, 'title' => '서울 여행']);
    }

    // ── GET /trips/{id} ──────────────────────────────────────────────

    public function testShowRedirectsWhenNotLoggedIn(): void
    {
        $result = $this->get('trips/1');

        $result->assertRedirect();
    }

    public function testShowRendersPageShell(): void
    {
        $result = $this->withSession(['user_id' => $this->userId])->get('trips/1');

        $result->assertStatus(200);
        $body = (string) $result->getBody();
        $this->assertStringContainsString('data-trip-id="1"', $body);
        // 인라인 시간표 펼치기에 필요한 timeline API URL과 토글 마크업이 포함돼야 한다.
        $this->assertStringContainsString('data-timeline-url', $body);
        $this->assertStringContainsString('day-timeline-toggle', $body);
    }

    // ── GET /trips/{id}/data ─────────────────────────────────────────

    public function testShowDataRequiresLogin(): void
    {
        $result = $this->get('trips/1/data');

        $result->assertStatus(401);
    }

    public function testShowDataReturns404WhenNotOwned(): void
    {
        $otherId = (new UserModel())->upsertByGoogleSub('sub-tripc-2', 'tripc2@example.com', 'TripC2');
        $tripId = (int) (new TripModel())->insert([
            'user_id' => $otherId, 'title' => 'T', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-16', 'cover_photo_id' => null,
        ]);

        $result = $this->withSession(['user_id' => $this->userId])->get('trips/' . $tripId . '/data');

        $result->assertStatus(404);
    }

    public function testShowDataReturnsTripAndDaySummaries(): void
    {
        (new PhotoLocationModel())->saveMany($this->userId, [
            new PhotoLocation('sd1', 37.5, 127.0, '2024-03-15 02:00:00'),
            new PhotoLocation('sd2', 37.5, 127.0, '2024-03-16 02:00:00'),
        ]);
        $tripId = (int) (new TripModel())->insert([
            'user_id' => $this->userId, 'title' => '서울 여행', 'body' => '고궁 투어',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-16', 'cover_photo_id' => null,
        ]);

        $result = $this->withSession(['user_id' => $this->userId])->get('trips/' . $tripId . '/data');

        $result->assertStatus(200);
        $data = json_decode($result->getJSON() ?? '', true);
        $this->assertSame('서울 여행', $data['trip']['title']);
        $this->assertCount(2, $data['days']);
        $this->assertSame('2024-03-15', $data['days'][0]['date']);
    }

    // ── POST /trips/{id}/update ──────────────────────────────────────

    public function testUpdateRequiresLogin(): void
    {
        $result = $this->post('trips/1/update', ['title' => 'T', 'body' => '', 'start_date' => '2024-03-15', 'end_date' => '2024-03-16']);

        $result->assertStatus(401);
    }

    public function testUpdateReturns404WhenNotOwned(): void
    {
        $otherId = (new UserModel())->upsertByGoogleSub('sub-tripc-3', 'tripc3@example.com', 'TripC3');
        $tripId = (int) (new TripModel())->insert([
            'user_id' => $otherId, 'title' => 'T', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-16', 'cover_photo_id' => null,
        ]);

        $result = $this->withSession(['user_id' => $this->userId])
            ->post('trips/' . $tripId . '/update', ['title' => 'X', 'body' => '', 'start_date' => '2024-03-15', 'end_date' => '2024-03-16']);

        $result->assertStatus(404);
    }

    public function testUpdateAllowsKeepingSameDateRange(): void
    {
        // 자기 자신의 기존 범위와 겹침 검사를 하면 항상 실패하므로, excludeId 로 제외돼야 한다.
        $tripId = (int) (new TripModel())->insert([
            'user_id' => $this->userId, 'title' => '서울 여행', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-16', 'cover_photo_id' => null,
        ]);

        $result = $this->withSession(['user_id' => $this->userId])
            ->post('trips/' . $tripId . '/update', ['title' => '수정된 제목', 'body' => '', 'start_date' => '2024-03-15', 'end_date' => '2024-03-16']);

        $result->assertStatus(200);
        $this->seeInDatabase('trips', ['id' => $tripId, 'title' => '수정된 제목']);
    }

    public function testUpdateRejectsOverlapWithOtherTrip(): void
    {
        (new TripModel())->insert([
            'user_id' => $this->userId, 'title' => '여행 A', 'body' => '',
            'start_date' => '2024-03-01', 'end_date' => '2024-03-03', 'cover_photo_id' => null,
        ]);
        $tripBId = (int) (new TripModel())->insert([
            'user_id' => $this->userId, 'title' => '여행 B', 'body' => '',
            'start_date' => '2024-03-10', 'end_date' => '2024-03-12', 'cover_photo_id' => null,
        ]);

        $result = $this->withSession(['user_id' => $this->userId])
            ->post('trips/' . $tripBId . '/update', ['title' => '여행 B', 'body' => '', 'start_date' => '2024-03-02', 'end_date' => '2024-03-12']);

        $result->assertStatus(422);
    }

    // ── POST /trips/{id}/delete ──────────────────────────────────────

    public function testDeleteRequiresLogin(): void
    {
        $result = $this->post('trips/1/delete');

        $result->assertStatus(401);
    }

    public function testDeleteReturns404WhenNotOwned(): void
    {
        $otherId = (new UserModel())->upsertByGoogleSub('sub-tripc-4', 'tripc4@example.com', 'TripC4');
        $tripId = (int) (new TripModel())->insert([
            'user_id' => $otherId, 'title' => 'T', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-16', 'cover_photo_id' => null,
        ]);

        $result = $this->withSession(['user_id' => $this->userId])->post('trips/' . $tripId . '/delete');

        $result->assertStatus(404);
        $this->seeInDatabase('trips', ['id' => $tripId]);
    }

    public function testDeleteRemovesTripButKeepsPhotosAndNotes(): void
    {
        (new PhotoLocationModel())->saveMany($this->userId, [
            new PhotoLocation('kd1', 37.5, 127.0, '2024-03-15 02:00:00'),
        ]);
        $tripId = (int) (new TripModel())->insert([
            'user_id' => $this->userId, 'title' => '서울 여행', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-16', 'cover_photo_id' => null,
        ]);

        $result = $this->withSession(['user_id' => $this->userId])->post('trips/' . $tripId . '/delete');

        $result->assertStatus(200);
        $this->dontSeeInDatabase('trips', ['id' => $tripId]);
        $this->seeInDatabase('photo_locations', ['source_item_id' => 'kd1']);
    }

    // ── POST /trips/{id}/share ───────────────────────────────────────

    public function testShareRequiresLogin(): void
    {
        $result = $this->post('trips/1/share');

        $result->assertStatus(401);
    }

    public function testShareReturns404WhenNotOwned(): void
    {
        $otherId = (new UserModel())->upsertByGoogleSub('sub-tripc-5', 'tripc5@example.com', 'TripC5');
        $tripId = (int) (new TripModel())->insert([
            'user_id' => $otherId, 'title' => 'T', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-16', 'cover_photo_id' => null,
        ]);

        $result = $this->withSession(['user_id' => $this->userId])->post('trips/' . $tripId . '/share');

        $result->assertStatus(404);
    }

    public function testShareReturnsShareUrl(): void
    {
        $tripId = (int) (new TripModel())->insert([
            'user_id' => $this->userId, 'title' => '서울 여행', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-16', 'cover_photo_id' => null,
        ]);

        $result = $this->withSession(['user_id' => $this->userId])->post('trips/' . $tripId . '/share');

        $result->assertStatus(200);
        $data = json_decode($result->getJSON() ?? '', true);
        $this->assertMatchesRegularExpression('#/t/[0-9a-f]{32}$#', $data['url']);
        $this->seeInDatabase('trip_share_links', ['trip_id' => $tripId]);
    }
}
