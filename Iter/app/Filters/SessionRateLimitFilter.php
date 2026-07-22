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
 *
 * 라우트별로 버킷·한도를 분리하려면 필터 인자를 쓴다: `sessionRateLimit:notes,120`
 * → 버킷 'notes', 기본 120회/시간(.env 의 ratelimit.notes.per_hour 로 재조정 가능).
 * 버킷이 다르면 카운터도 분리되므로 업로드 한도와 메모 저장 한도가 서로를 소진하지 않는다.
 */
class SessionRateLimitFilter implements FilterInterface
{
    private const DEFAULT_PER_HOUR = 10;
    private const DEFAULT_BUCKET = 'session';

    /**
     * @param list<string>|null $arguments [버킷명, 시간당 한도] — 생략 시 기본 버킷·기본 한도
     *
     * @return ResponseInterface|null
     *
     * @phpstan-impure 토큰 버킷을 소모하므로 같은 인자로 불러도 결과가 달라진다
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $throttler = service('throttler');

        $bucket = isset($arguments[0]) && $arguments[0] !== '' ? (string) $arguments[0] : self::DEFAULT_BUCKET;
        $fallbackPerHour = isset($arguments[1]) && is_numeric($arguments[1])
            ? max(1, (int) $arguments[1])
            : self::DEFAULT_PER_HOUR;

        if ($throttler->check($this->key($request, $bucket), $this->perHour($bucket, $fallbackPerHour), HOUR, 1) === false) {
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
     * 레이트 리밋 키 — 로그인 사용자는 user_id, 아니면 IP 기준. 버킷별로 카운터를 분리한다.
     */
    private function key(RequestInterface $request, string $bucket): string
    {
        $userId = session()->get('user_id');
        $who = is_int($userId) && $userId > 0 ? 'user_' . $userId : 'ip_' . $request->getIPAddress();

        // 기본 버킷은 기존 키('session_create_...')를 유지해 배포 시 카운터가 리셋되지 않게 한다.
        $prefix = $bucket === self::DEFAULT_BUCKET ? 'session_create_' : 'ratelimit_' . $bucket . '_';

        // 캐시 키 예약문자({}()/\@: 및 IPv6 콜론)를 '_' 로 치환해 안전하게 만든다.
        return $prefix . preg_replace('/[^A-Za-z0-9_.-]/', '_', $who);
    }

    /**
     * 시간당 허용 요청 횟수 — .env 의 ratelimit.{버킷}.per_hour 가 있으면 우선한다.
     */
    private function perHour(string $bucket, int $fallback): int
    {
        $value = env('ratelimit.' . $bucket . '.per_hour', $fallback);

        return is_numeric($value) ? max(1, (int) $value) : $fallback;
    }
}
