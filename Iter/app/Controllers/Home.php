<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * 랜딩 페이지 — 로그인 여부와 무관하게 항상 렌더된다(GET /).
 * 로그인 후 업로드 화면은 GET /upload(TakeoutController::form)에서 별도로 제공한다.
 */
class Home extends BaseController
{
    public function index(): string
    {
        helper('url');

        return view('landing', [
            'loginUrl' => site_url('auth/google'),
        ]);
    }
}
