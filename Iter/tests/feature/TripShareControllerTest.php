<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PhotoLocationModel;
use App\Models\TripModel;
use App\Models\TripShareLinkModel;
use App\Models\UserModel;
use App\Services\Ingest\PhotoLocation;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class TripShareControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $refresh = true;
    protected $namespace = 'App';

    private int $userId;
    private int $tripId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (new UserModel())->upsertByGoogleSub('sub-tripsharec', 'tripsharec@example.com', 'TripShareC');
        $this->tripId = (int) (new TripModel())->insert([
            'user_id' => $this->userId, 'title' => '서울 여행', 'body' => '고궁 투어',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-16', 'cover_photo_id' => null,
        ]);
    }

    public function testShowReturns404ForUnknownToken(): void
    {
        $result = $this->get('t/' . str_repeat('0', 32));

        $result->assertStatus(404);
    }

    public function testShowOpensWithoutLoginAndShowsSummary(): void
    {
        (new PhotoLocationModel())->saveMany($this->userId, [
            new PhotoLocation('t1', 37.5, 127.0, '2024-03-15 02:05:00'), // KST 3/15 11:05
        ]);
        $token = (new TripShareLinkModel())->createOrGet($this->tripId);

        $result = $this->get('t/' . $token);

        $result->assertStatus(200);
        $body = html_entity_decode((string) $result->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringContainsString('서울 여행', $body);
        $this->assertStringContainsString('고궁 투어', $body);
        $this->assertStringContainsString('3월 15일', $body);
        $this->assertStringContainsString('og:title', $body);
    }

    public function testThumbnailServesOwnerPhotoWithinTripRange(): void
    {
        $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_') . '.jpg';
        file_put_contents($thumbPath, 'trip-shared-bytes');
        (new PhotoLocationModel())->saveMany($this->userId, [
            new PhotoLocation('t1', 37.5, 127.0, '2024-03-15 02:05:00', $thumbPath),
        ]);
        $photoId = (int) (new PhotoLocationModel())->where('source_item_id', 't1')->first()['id'];
        $token = (new TripShareLinkModel())->createOrGet($this->tripId);

        try {
            $result = $this->get('t/' . $token . '/thumbnails/' . $photoId);

            $result->assertStatus(200);
            $this->assertSame('trip-shared-bytes', $result->response()->getBody());
        } finally {
            if (is_file($thumbPath)) {
                unlink($thumbPath);
            }
        }
    }

    public function testThumbnailRejectsPhotoOutsideTripRange(): void
    {
        $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_') . '.jpg';
        file_put_contents($thumbPath, 'outside-range');
        (new PhotoLocationModel())->saveMany($this->userId, [
            new PhotoLocation('out', 37.5, 127.0, '2024-03-25 02:05:00', $thumbPath),
        ]);
        $photoId = (int) (new PhotoLocationModel())->where('source_item_id', 'out')->first()['id'];
        $token = (new TripShareLinkModel())->createOrGet($this->tripId);

        try {
            $result = $this->get('t/' . $token . '/thumbnails/' . $photoId);

            $result->assertStatus(404);
        } finally {
            if (is_file($thumbPath)) {
                unlink($thumbPath);
            }
        }
    }

    public function testThumbnailRejectsOtherUsersPhoto(): void
    {
        $otherId = (new UserModel())->upsertByGoogleSub('sub-tripsharec-2', 'tripsharec2@example.com', 'TripShareC2');
        $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_') . '.jpg';
        file_put_contents($thumbPath, 'not-mine');
        (new PhotoLocationModel())->saveMany($otherId, [
            new PhotoLocation('theirs', 37.5, 127.0, '2024-03-15 02:05:00', $thumbPath),
        ]);
        $photoId = (int) (new PhotoLocationModel())->where('source_item_id', 'theirs')->first()['id'];
        $token = (new TripShareLinkModel())->createOrGet($this->tripId);

        try {
            $result = $this->get('t/' . $token . '/thumbnails/' . $photoId);

            $result->assertStatus(404);
        } finally {
            if (is_file($thumbPath)) {
                unlink($thumbPath);
            }
        }
    }
}
