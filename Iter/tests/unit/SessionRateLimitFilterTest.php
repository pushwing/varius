<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filters\SessionRateLimitFilter;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

/**
 * 실제 throttler(파일 캐시)를 타는 회귀 테스트 — 캐시 키 예약문자 등 런타임 결함을 잡는다.
 *
 * @internal
 */
final class SessionRateLimitFilterTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean(); // throttler 상태 초기화(테스트 격리)
    }

    public function testAllowsRequestWithSafeCacheKeyForLoggedInUser(): void
    {
        session()->set('user_id', 42);

        $result = (new SessionRateLimitFilter())->before(Services::request());

        // 첫 요청은 한도 내이므로 통과(응답 반환 없음). 예약문자 키였다면 예외로 실패한다.
        // (429 차단 의미는 throttler 를 Mock 한 PickerControllerTest 가 커버한다.)
        $this->assertNull($result);
    }
}
