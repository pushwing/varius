<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\GoogleApiName;
use CodeIgniter\Cache\CacheInterface;

/**
 * Google Photos API 호출량을 일 단위로 집계·기록한다.
 *
 * Google 프로젝트 단위 쿼터 소진을 조기에 감지할 수 있도록, 호출마다 캐시 카운터를
 * 누적하고 로그로 남긴다(403·429 는 쿼터 초과 신호로 보고 warning 레벨로 기록).
 * 엄밀한 과금 기록이 아니라 "소진 속도를 눈으로 확인하는" 경량 모니터링 지점이다.
 */
class GoogleApiUsageTracker
{
    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * 호출 1건을 기록하고 오늘 누적 호출 수를 반환한다.
     */
    public function record(GoogleApiName $api, int $statusCode): int
    {
        $count = $this->countToday($api) + 1;
        $this->cache->save($this->cacheKey($api), $count, DAY);

        $level = in_array($statusCode, [403, 429], true) ? 'warning' : 'info';
        log_message($level, 'Google API 호출: api={api} status={status} today_count={count}', [
            'api' => $api->value,
            'status' => $statusCode,
            'count' => $count,
        ]);

        return $count;
    }

    /**
     * 오늘(로컬 날짜 기준) 누적 호출 수를 조회한다.
     */
    public function countToday(GoogleApiName $api): int
    {
        $value = $this->cache->get($this->cacheKey($api));

        return is_int($value) ? $value : 0;
    }

    private function cacheKey(GoogleApiName $api): string
    {
        return 'google_api_calls_' . $api->value . '_' . date('Y-m-d');
    }
}
