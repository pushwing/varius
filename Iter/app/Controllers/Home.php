<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * 랜딩 페이지 — 로그인 여부와 무관하게 항상 렌더된다(GET /).
 * 로그인 사용자에게는 상단 메뉴와 "사진 가져오기" 버튼을 보여주고, 로그인 CTA는 숨긴다.
 * 업로드 폼 자체는 GET /upload(TakeoutController::form)에서 별도로 제공한다.
 */
class Home extends BaseController
{
    public function index(): string
    {
        helper('url');

        return view('landing', [
            'userId' => $this->currentUserId(),
            'loginUrl' => site_url('auth/google'),
            'uploadUrl' => site_url('upload'),
            'mapUrl' => site_url('map'),
            'tripsUrl' => site_url('trips'),
            'logoutUrl' => site_url('auth/logout'),
        ]);
    }
}
