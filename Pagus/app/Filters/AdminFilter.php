<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

final class AdminFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): RequestInterface|ResponseInterface|null
    {
        if (session()->get('is_admin') !== true) {
            return redirect()->to('/login')->with('error', '운영자 로그인이 필요합니다.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ?ResponseInterface
    {
        return null;
    }
}
