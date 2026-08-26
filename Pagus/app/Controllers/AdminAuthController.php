<?php

namespace App\Controllers;

use App\Services\AdminAuthService;
use CodeIgniter\Controller;

final class AdminAuthController extends Controller
{
    public function login(): string
    {
        return view('auth/login');
    }

    public function authenticate(): \CodeIgniter\HTTP\RedirectResponse
    {
        $rules = ['email' => 'required|valid_email', 'password' => 'required'];
        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('error', '이메일과 비밀번호를 확인하세요.');
        }

        $service = new AdminAuthService();
        if (! $service->login((string) $this->request->getPost('email'), (string) $this->request->getPost('password'))) {
            return redirect()->back()->withInput()->with('error', '로그인 정보가 올바르지 않습니다.');
        }

        return redirect()->to('/admin');
    }

    public function logout(): \CodeIgniter\HTTP\RedirectResponse
    {
        (new AdminAuthService())->logout();
        return redirect()->to('/login');
    }
}
