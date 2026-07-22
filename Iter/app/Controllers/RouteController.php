<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\PhotoLocationModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * 날짜별 동선 시각화 — 동선 JSON API + Leaflet 지도 뷰.
 *
 * 조합 로직은 RouteVisualizationService 에 위임하고, 컨트롤러는 인증 가드·응답만 담당한다.
 */
class RouteController extends BaseController
{
    /**
     * 동선 데이터(JSON) — 로그인 사용자의 날짜별 동선을 반환한다(GET /routes).
     */
    public function data(): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => '로그인이 필요합니다.']);
        }

        $routes = service('routeVisualization')->buildForUser($userId);

        return $this->response->setJSON($routes);
    }

    /**
     * 지도 페이지(Leaflet) — 동선을 마커·경로선으로 렌더한다(GET /map).
     * 미로그인 시 OAuth 로그인으로 리다이렉트한다.
     */
    public function map(): ResponseInterface|RedirectResponse|string
    {
        if ($this->currentUserId() === null) {
            return redirect()->to('/auth/google');
        }

        helper('url');

        return view('map', [
            'routesUrl' => site_url('routes'),
            'timelineUrl' => site_url('timeline'),
            'photosUrl' => site_url('photos'),
            'tripsUrl' => site_url('trips'),
            'uploadUrl' => site_url('upload'),
            'mapUrl' => site_url('map'),
            'logoutUrl' => site_url('auth/logout'),
        ]);
    }

    /**
     * 좌표 지점의 썸네일 이미지 바이트를 반환한다(GET /thumbnails/{id}).
     * 요청자 소유의 좌표가 아니면 404(존재 노출 방지 목적으로 403 대신 404).
     */
    public function thumbnail(int $id): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => '로그인이 필요합니다.']);
        }

        $path = model(PhotoLocationModel::class)->thumbnailPathFor($id, $userId);
        if ($path === null || ! is_file($path)) {
            return $this->response->setStatusCode(404);
        }

        return $this->response
            ->removeHeader('Cache-Control')
            ->setHeader('Cache-Control', 'private, max-age=86400')
            ->setContentType('image/jpeg')
            ->setBody((string) file_get_contents($path));
    }
}
