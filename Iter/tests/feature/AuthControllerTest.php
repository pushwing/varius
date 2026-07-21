<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\GooglePhotosAuthService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * @internal
 */
final class AuthControllerTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use DatabaseTestTrait;

    protected $refresh = true;
    protected $namespace = 'App';

    public function testRedirectSendsUserToGoogleAndStoresState(): void
    {
        $result = $this->get('/auth/google');

        $result->assertRedirect();
        $this->assertStringContainsString('accounts.google.com', $result->getRedirectUrl());
        // CSRF 방지용 state 가 세션에 저장돼야 한다.
        $this->assertNotEmpty(session()->get('oauth_state'));
    }

    public function testCallbackRejectsMismatchedState(): void
    {
        $result = $this->withSession(['oauth_state' => 'expected-state'])
            ->get('/auth/google/callback?state=tampered&code=any');

        $result->assertStatus(400);
    }

    public function testCallbackRejectsMissingCode(): void
    {
        $result = $this->withSession(['oauth_state' => 'matching-state'])
            ->get('/auth/google/callback?state=matching-state');

        $result->assertStatus(400);
    }

    public function testCallbackWithValidStateAuthenticatesUser(): void
    {
        // Google 토큰 교환은 목으로 대체하고, state 검증 통과 후 인증이 성립하는지만 확인한다.
        $auth = $this->createMock(GooglePhotosAuthService::class);
        $auth->method('handleCallback')->willReturn(7);
        Services::injectMock('googlePhotosAuth', $auth);

        $result = $this->withSession(['oauth_state' => 'matching-state'])
            ->get('/auth/google/callback?state=matching-state&code=auth-code');

        // 세션 ID 재발급(세션 고정 방어) 후에도 인증 흐름이 정상 완료돼야 한다.
        $result->assertRedirectTo('/upload');
        $this->assertSame(7, session()->get('user_id'));

        Services::reset();
    }

    public function testLogoutDestroysSessionAndRedirects(): void
    {
        $result = $this->withSession(['user_id' => 42])->get('auth/logout');

        $result->assertRedirect();
        $this->assertNull(session()->get('user_id'));
    }

    public function testProtectedRouteRequires401AfterLogout(): void
    {
        $this->withSession(['user_id' => 42])->get('auth/logout');

        // withSession() 를 인자 없이 호출하면 로그아웃으로 변경된 현재 $_SESSION 을 다시 캡처한다
        // (FeatureTestTrait::call() 은 매 요청마다 $_SESSION 을 마지막 withSession() 스냅샷으로 되돌리므로,
        // 재캡처하지 않으면 두 번째 요청이 로그아웃 이전 세션으로 되돌아간다).
        $result = $this->withSession()->get('routes');

        $result->assertStatus(401);
    }
}
