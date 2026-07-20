<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Google OAuth2 로그인 진입·콜백 컨트롤러.
 *
 * 로직은 GooglePhotosAuthService 에 위임하고, 컨트롤러는 입력 검증·세션·응답만 담당한다.
 */
class AuthController extends BaseController
{
    /**
     * OAuth 로그인 시작 — Google 인가 화면으로 리다이렉트한다.
     */
    public function redirect(): RedirectResponse
    {
        $state = bin2hex(random_bytes(16));
        session()->set('oauth_state', $state);

        $url = service('googlePhotosAuth')->getAuthorizationUrl($state);

        return redirect()->to($url);
    }

    /**
     * OAuth 콜백 — state 검증 후 토큰을 발급·저장한다.
     */
    public function callback(): ResponseInterface|RedirectResponse
    {
        $state = (string) $this->request->getGet('state');
        $sessionState = (string) session()->get('oauth_state');
        session()->remove('oauth_state');

        // CSRF: 세션 state 와 콜백 state 가 일치해야 한다.
        if ($state === '' || $sessionState === '' || ! hash_equals($sessionState, $state)) {
            return $this->response->setStatusCode(400, 'Invalid OAuth state');
        }

        $code = (string) $this->request->getGet('code');
        if ($code === '') {
            return $this->response->setStatusCode(400, 'Missing authorization code');
        }

        try {
            $userId = service('googlePhotosAuth')->handleCallback($code);
        } catch (Throwable $e) {
            // 토큰 원문은 로그·응답에 남기지 않는다.
            log_message('error', 'OAuth 콜백 처리 실패: {msg}', ['msg' => $e->getMessage()]);

            return $this->response->setStatusCode(400, 'OAuth callback failed');
        }

        session()->set('user_id', $userId);

        return redirect()->to('/')->with('message', '로그인이 완료되었습니다.');
    }

    /**
     * 로그아웃 — 인증 상태를 지우고 홈으로 리다이렉트한다(GET /auth/logout).
     *
     * session()->destroy() 는 CI4 testing 환경에서 no-op 이라(Session::destroy() 참고)
     * 테스트로 검증 가능한 remove() 로 인증 키만 명시적으로 제거한다.
     */
    public function logout(): RedirectResponse
    {
        session()->remove('user_id');

        return redirect()->to('/');
    }
}
