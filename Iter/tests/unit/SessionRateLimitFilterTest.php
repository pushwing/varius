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
        // (429 차단 의미는 throttler 를 Mock 한 TakeoutControllerTest 가 커버한다.)
        $this->assertNull($result);
    }

    public function testArgumentLimitBlocksAfterExceeded(): void
    {
        session()->set('user_id', 43);
        $filter = new SessionRateLimitFilter();

        // 인자(버킷, 시간당 한도)로 한도를 라우트별 조정할 수 있다: sessionRateLimit:tiny,2
        $this->assertNull($filter->before(Services::request(), ['tiny', '2']));
        $this->assertNull($filter->before(Services::request(), ['tiny', '2']));

        $blocked = $filter->before(Services::request(), ['tiny', '2']);
        $this->assertNotNull($blocked);
        $this->assertSame(429, $blocked->getStatusCode());
    }

    public function testNamedBucketIsIsolatedFromDefaultBucket(): void
    {
        session()->set('user_id', 44);
        $filter = new SessionRateLimitFilter();

        // 기본 버킷(무인자, 10회/시간)을 모두 소진해도,
        for ($i = 0; $i < 10; $i++) {
            $this->assertNull($filter->before(Services::request()));
        }
        $this->assertNotNull($filter->before(Services::request()));

        // 별도 버킷(notes)은 영향받지 않고 통과해야 한다(업로드와 메모 저장 한도 분리).
        $this->assertNull($filter->before(Services::request(), ['notes', '120']));
    }
}
