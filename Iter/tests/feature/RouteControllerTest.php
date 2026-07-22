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

    public function testMapPageIncludesLoggedInNav(): void
    {
        // 로그인 후 상단 메뉴(사진 가져오기·지도 보기·내 여행·로그아웃)는 업로드 페이지뿐 아니라
        // 지도 페이지에서도 동일하게 보여야 한다.
        $userId = (new UserModel())->upsertByGoogleSub('sub-map-nav', 'mapnav@example.com', 'MapNav');

        $result = $this->withSession(['user_id' => $userId])->get('map');

        $result->assertStatus(200);
        $body = html_entity_decode((string) $result->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringContainsString('class="brand"', $body);
        $this->assertStringContainsString('지도 보기', $body);
        $this->assertStringContainsString('내 여행', $body);
        $this->assertStringContainsString('/trips', $body);
        $this->assertStringContainsString('로그아웃', $body);
        $this->assertStringContainsString('/auth/logout', $body);
    }

    public function testMapPageIncludesTimelineLayer(): void
    {
        // 사이드바 날짜 옆 "시간표" 진입과 시간별 동선 레이어(여행 스케줄 뷰)가 지도 페이지에 포함돼야 한다.
        $userId = (new UserModel())->upsertByGoogleSub('sub-map-tl', 'maptl@example.com', 'MapTl');

        $result = $this->withSession(['user_id' => $userId])->get('map');

        $result->assertStatus(200);
        $body = (string) $result->getBody();
        $this->assertStringContainsString('id="timeline-layer"', $body);
        $this->assertStringContainsString('data-timeline-url', $body);
        // 사진 클릭 시 보관된 이미지를 크게 보는 확대 뷰어도 포함돼야 한다.
        $this->assertStringContainsString('id="photo-viewer"', $body);
        // 뷰어에는 회전·삭제 컨트롤이 있어야 한다.
        $this->assertStringContainsString('id="photo-viewer-controls"', $body);
        $this->assertStringContainsString('data-photos-url', $body);
        // 사진이 여러 장일 때 넘겨볼 수 있는 좌우 이동 버튼도 있어야 한다.
        $this->assertStringContainsString('id="photo-viewer-prev"', $body);
        $this->assertStringContainsString('id="photo-viewer-next"', $body);
        // SNS 공유 버튼(#19)도 시간표 상단에 있어야 한다.
        $this->assertStringContainsString('id="timeline-share-toggle"', $body);
        $this->assertStringContainsString('data-share="x"', $body);
        $this->assertStringContainsString('data-share="kakao"', $body);
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
