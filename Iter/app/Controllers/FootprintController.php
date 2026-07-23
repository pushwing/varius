<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * 발자국 지도 — 방문 국가·시·도 choropleth 페이지와 집계 JSON.
 */
final class FootprintController extends BaseController
{
    /**
     * 발자국 페이지(GET /footprint). 미로그인 시 OAuth 로그인으로 리다이렉트한다.
     */
    public function page(): ResponseInterface|RedirectResponse|string
    {
        if ($this->currentUserId() === null) {
            return redirect()->to('/auth/google');
        }

        helper('url');

        return view('footprint', [
            'dataUrl' => site_url('footprint/data'),
            'uploadUrl' => site_url('upload'),
            'mapUrl' => site_url('map'),
            'tripsUrl' => site_url('trips'),
            'logoutUrl' => site_url('auth/logout'),
        ]);
    }

    /**
     * 집계 JSON(GET /footprint/data).
     */
    public function data(): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => '로그인이 필요합니다.']);
        }

        return $this->response->setJSON(service('footprint')->buildForUser($userId));
    }
}
