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
}
