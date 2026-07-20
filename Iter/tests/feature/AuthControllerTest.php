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
}
