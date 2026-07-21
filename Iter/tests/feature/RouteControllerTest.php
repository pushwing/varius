<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PhotoLocationModel;
use App\Models\UserModel;
use App\Services\Ingest\PhotoLocation;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class RouteControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $refresh = true;
    protected $namespace = 'App';

    public function testRoutesRequiresLogin(): void
    {
        $result = $this->get('routes');

        $result->assertStatus(401);
    }

    public function testRoutesReturnsGroupedRoutesJson(): void
    {
        $userId = (new UserModel())->upsertByGoogleSub('sub-route', 'route@example.com', 'Route');
        (new PhotoLocationModel())->saveMany($userId, [
            new PhotoLocation('m1', 37.5, 127.0, '2024-03-15 09:00:00'),
            new PhotoLocation('m2', 37.6, 127.1, '2024-03-15 12:00:00'),
            new PhotoLocation('m3', 35.1, 129.0, '2024-03-16 08:00:00'),
        ]);

        $result = $this->withSession(['user_id' => $userId])->get('routes');

        $result->assertStatus(200);

        $data = json_decode($result->getJSON() ?? '', true);
        $this->assertIsArray($data);
        $this->assertCount(2, $data['dates']);
        $this->assertSame('2024-03-15', $data['dates'][0]['date']);
        $this->assertCount(2, $data['dates'][0]['points']);
        $this->assertSame('m1', $data['dates'][0]['points'][0]['media_item_id']);
    }

    public function testMapRedirectsWhenNotLoggedIn(): void
    {
        $result = $this->get('map');

        $result->assertRedirect();
    }

    public function testMapRendersLeafletPageWhenLoggedIn(): void
    {
        $userId = (new UserModel())->upsertByGoogleSub('sub-map', 'map@example.com', 'Map');

        $result = $this->withSession(['user_id' => $userId])->get('map');

        $result->assertStatus(200);
        $body = (string) $result->getBody();
        $this->assertStringContainsString('id="map"', $body);
        // Leaflet 자산을 로드해야 한다.
        $this->assertStringContainsString('leaflet', $body);
    }

    public function testThumbnailRequiresLogin(): void
    {
        $result = $this->get('thumbnails/1');

        $result->assertStatus(401);
    }

    public function testThumbnailReturns404WhenNotOwnedByUser(): void
    {
        $userId = (new UserModel())->upsertByGoogleSub('sub-thumb-1', 'thumb1@example.com', 'Thumb1');

        $result = $this->withSession(['user_id' => $userId])->get('thumbnails/999999');

        $result->assertStatus(404);
    }

    public function testThumbnailServesImageBytesWhenOwned(): void
    {
        $userId = (new UserModel())->upsertByGoogleSub('sub-thumb-2', 'thumb2@example.com', 'Thumb2');

        $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_') . '.jpg';
        file_put_contents($thumbPath, 'fake-jpeg-bytes');

        (new PhotoLocationModel())->saveMany($userId, [
            new PhotoLocation('m1', 37.5, 127.0, '2024-03-15 09:00:00', $thumbPath),
        ]);
        $id = (int) (new PhotoLocationModel())->where('source_item_id', 'm1')->first()['id'];

        try {
            $result = $this->withSession(['user_id' => $userId])->get('thumbnails/' . $id);

            $result->assertStatus(200);
            // TestResponse::getBody() 는 원본 바이트가 아니라 DOMParser 로 포워딩되는
            // HTML 파싱용 헬퍼라 이미지 바이트를 그대로 볼 수 없다 — 원본 응답은
            // response()->getBody() 로 접근한다.
            $this->assertSame('fake-jpeg-bytes', $result->response()->getBody());
            $this->assertStringContainsString('image/jpeg', $result->response()->getHeaderLine('Content-Type'));
            $this->assertSame('private, max-age=86400', $result->response()->getHeaderLine('Cache-Control'));
        } finally {
            if (is_file($thumbPath)) {
                unlink($thumbPath);
            }
        }
    }
}
