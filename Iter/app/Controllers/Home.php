<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * 랜딩 페이지 — 비로그인 사용자 전용(GET /). 로그인 사용자는 /upload 로 리다이렉트한다.
 */
class Home extends BaseController
{
    public function index(): RedirectResponse|string
    {
        if ($this->currentUserId() !== null) {
            return redirect()->to('/upload');
        }

        helper('url');

        return view('landing', [
            'loginUrl' => site_url('auth/google'),
        ]);
    }
}
