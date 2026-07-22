<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DayNoteModel;
use App\Models\PhotoLocationModel;
use App\Models\ShareLinkModel;
use App\Models\UserModel;
use App\Services\Ingest\PhotoLocation;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class ShareControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $refresh = true;
    protected $namespace = 'App';

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (new UserModel())->upsertByGoogleSub('sub-sharec', 'sharec@example.com', 'ShareC');
    }

    // ── POST /timeline/share ─────────────────────────────────────────

    public function testCreateShareRequiresLogin(): void
    {
        $result = $this->post('timeline/share', ['date' => '2024-03-15']);

        $result->assertStatus(401);
    }

    public function testCreateShareRejectsInvalidDate(): void
    {
        $result = $this->withSession(['user_id' => $this->userId])
            ->post('timeline/share', ['date' => 'nope']);

        $result->assertStatus(422);
    }

    public function testCreateShareReturnsShareUrl(): void
    {
        $result = $this->withSession(['user_id' => $this->userId])
            ->post('timeline/share', ['date' => '2024-03-15']);

        $result->assertStatus(200);
        $data = json_decode($result->getJSON() ?? '', true);
        $this->assertIsArray($data);
        $this->assertMatchesRegularExpression('#/s/[0-9a-f]{32}$#', $data['url']);
        $this->seeInDatabase('share_links', ['user_id' => $this->userId, 'share_date' => '2024-03-15']);
    }

    // ── GET /s/{token} ───────────────────────────────────────────────

    public function testSharedPageReturns404ForUnknownToken(): void
    {
        $result = $this->get('s/' . str_repeat('0', 32));

        $result->assertStatus(404);
    }

    public function testSharedPageOpensWithoutLoginAndShowsSchedule(): void
    {
        // 저장은 UTC — KST 로 2024-03-15 11:05.
        (new PhotoLocationModel())->saveMany($this->userId, [
            new PhotoLocation('s1', 37.5, 127.0, '2024-03-15 02:05:00'),
        ]);
        (new DayNoteModel())->upsertNote($this->userId, '2024-03-15', '서울 여행 1일차', '고궁 투어');
        $token = (new ShareLinkModel())->createOrGet($this->userId, '2024-03-15');

        // 로그인 세션 없이 접근한다.
        $result = $this->get('s/' . $token);

        $result->assertStatus(200);
        $body = html_entity_decode((string) $result->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringContainsString('서울 여행 1일차', $body);
        $this->assertStringContainsString('11:05', $body);
        // SNS 미리보기용 OG 태그.
        $this->assertStringContainsString('og:title', $body);
    }

    // ── GET /s/{token}/thumbnails/{id} ───────────────────────────────

    public function testSharedThumbnailServesOwnerPhotoWithoutLogin(): void
    {
        $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_') . '.jpg';
        file_put_contents($thumbPath, 'shared-jpeg-bytes');
        (new PhotoLocationModel())->saveMany($this->userId, [
            new PhotoLocation('s1', 37.5, 127.0, '2024-03-15 02:05:00', $thumbPath),
        ]);
        $photoId = (int) (new PhotoLocationModel())->where('source_item_id', 's1')->first()['id'];
        $token = (new ShareLinkModel())->createOrGet($this->userId, '2024-03-15');

        try {
            $result = $this->get('s/' . $token . '/thumbnails/' . $photoId);

            $result->assertStatus(200);
            $this->assertSame('shared-jpeg-bytes', $result->response()->getBody());
        } finally {
            if (is_file($thumbPath)) {
                unlink($thumbPath);
            }
        }
    }

    public function testSharedThumbnailRejectsPhotoOutsideSharedDate(): void
    {
        // 공유된 날짜(3/15 KST) 밖의 사진은 토큰으로 열람할 수 없어야 한다.
        $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_') . '.jpg';
        file_put_contents($thumbPath, 'other-date-bytes');
        (new PhotoLocationModel())->saveMany($this->userId, [
            new PhotoLocation('other', 37.5, 127.0, '2024-03-20 02:05:00', $thumbPath),
        ]);
        $photoId = (int) (new PhotoLocationModel())->where('source_item_id', 'other')->first()['id'];
        $token = (new ShareLinkModel())->createOrGet($this->userId, '2024-03-15');

        try {
            $result = $this->get('s/' . $token . '/thumbnails/' . $photoId);

            $result->assertStatus(404);
        } finally {
            if (is_file($thumbPath)) {
                unlink($thumbPath);
            }
        }
    }

    public function testSharedThumbnailRejectsOtherUsersPhoto(): void
    {
        $otherId = (new UserModel())->upsertByGoogleSub('sub-sharec-2', 'sharec2@example.com', 'ShareC2');
        $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_') . '.jpg';
        file_put_contents($thumbPath, 'other-user-bytes');
        (new PhotoLocationModel())->saveMany($otherId, [
            new PhotoLocation('theirs', 37.5, 127.0, '2024-03-15 02:05:00', $thumbPath),
        ]);
        $photoId = (int) (new PhotoLocationModel())->where('source_item_id', 'theirs')->first()['id'];
        $token = (new ShareLinkModel())->createOrGet($this->userId, '2024-03-15');

        try {
            $result = $this->get('s/' . $token . '/thumbnails/' . $photoId);

            $result->assertStatus(404);
        } finally {
            if (is_file($thumbPath)) {
                unlink($thumbPath);
            }
        }
    }
}
