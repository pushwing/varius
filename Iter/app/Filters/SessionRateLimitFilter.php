<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * 요청 레이트 리밋(사용자당·미로그인 시 IP당 시간당 N회).
 *
 * CI4 throttler(토큰 버킷)로 한도를 관리하고, 초과 시 429 로 차단한다.
 * 한도는 .env 의 ratelimit.session.per_hour 로 조정한다(기본 10회/시간).
 */
class SessionRateLimitFilter implements FilterInterface
{
    private const DEFAULT_PER_HOUR = 10;

    /**
     * @param list<string>|null $arguments
     *
     * @return ResponseInterface|null
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $throttler = service('throttler');

        if ($throttler->check($this->key($request), $this->perHour(), HOUR, 1) === false) {
            return service('response')
                ->setStatusCode(429)
                ->setJSON(['error' => '요청이 너무 잦습니다. 잠시 후 다시 시도하세요.']);
        }

        return null;
    }

    /**
     * @param list<string>|null $arguments
     *
     * @return ResponseInterface|null
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }

    /**
     * 레이트 리밋 키 — 로그인 사용자는 user_id, 아니면 IP 기준.
     */
    private function key(RequestInterface $request): string
    {
        $userId = session()->get('user_id');
        $who = is_int($userId) && $userId > 0 ? 'user_' . $userId : 'ip_' . $request->getIPAddress();

        // 캐시 키 예약문자({}()/\@: 및 IPv6 콜론)를 '_' 로 치환해 안전하게 만든다.
        return 'session_create_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $who);
    }

    /**
     * 시간당 허용 요청 횟수(.env 설정, 기본 10).
     */
    private function perHour(): int
    {
        $value = env('ratelimit.session.per_hour', self::DEFAULT_PER_HOUR);

        return is_numeric($value) ? max(1, (int) $value) : self::DEFAULT_PER_HOUR;
    }
}
