<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

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
        $result = $this->withSession()->post('picker/sessions');

        $result->assertStatus(401);
    }
}
