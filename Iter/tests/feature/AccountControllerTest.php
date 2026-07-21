<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AccountDeletionService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use RuntimeException;

/**
 * @internal
 */
final class AccountControllerTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean(); // sessionRateLimit throttler 상태 초기화(테스트 격리)
    }

    public function testDeleteRedirectsToAuthWhenNotLoggedIn(): void
    {
        $result = $this->post('account/delete');

        $result->assertRedirect();
        $this->assertStringContainsString('/auth/google', $result->getRedirectUrl());
    }

    public function testDeleteInvokesServiceClearsSessionAndRedirectsHome(): void
    {
        $deletion = $this->createMock(AccountDeletionService::class);
        $deletion->expects($this->once())->method('deleteAllUserData')->with(42);
        Services::injectMock('accountDeletion', $deletion);

        $result = $this->withSession(['user_id' => 42])->post('account/delete');

        $result->assertRedirectTo('/');
        $this->assertNull(session()->get('user_id'));

        Services::reset();
    }

    public function testDeleteRedirectsBackToUploadWhenServiceFails(): void
    {
        $deletion = $this->createMock(AccountDeletionService::class);
        $deletion->method('deleteAllUserData')->willThrowException(new RuntimeException('삭제 실패'));
        Services::injectMock('accountDeletion', $deletion);

        $result = $this->withSession(['user_id' => 42])->post('account/delete');

        $result->assertRedirect();
        $this->assertStringContainsString('/upload', $result->getRedirectUrl());
        // 삭제 실패 시 세션은 유지된다(사용자가 재시도할 수 있도록).
        $this->assertSame(42, session()->get('user_id'));

        Services::reset();
    }
}
