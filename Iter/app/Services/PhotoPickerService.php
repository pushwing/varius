<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;

/**
 * Google Photos Picker 세션 흐름.
 *
 * 세션 생성(pickerUri 발급) → 사용자 선택 대기 폴링(mediaItemsSet) → 선택 항목 조회를
 * 담당한다. 요청당 최대 매수(기본 10장)로 잘라낸다.
 *
 * access token 은 상태로 보관하지 않고 매 메서드 인자로 받는다(향후 큐 워커 분리 대비).
 * 액세스 토큰 원문은 예외·로그로 노출하지 않는다.
 */
final class PhotoPickerService
{
    private const BASE_URL = 'https://photospicker.googleapis.com/v1';

    /**
     * 폴링 간격 파싱 실패 시 사용할 기본 대기 초.
     */
    private const DEFAULT_POLL_INTERVAL = 2;

    /**
     * @var Closure(int): void sleep 을 감싼 대기 함수(테스트에서 주입 가능).
     */
    private readonly Closure $sleeper;

    /**
     * @param int          $maxItems        요청당 반환 상한(10장 제한).
     * @param Closure(int): void|null $sleeper 대기 함수. null 이면 실제 sleep 사용.
     * @param int          $maxPollAttempts 폴링 최대 시도 횟수(무한 루프 방지).
     */
    public function __construct(
        private readonly CURLRequest $client,
        private readonly int $maxItems = 10,
        ?Closure $sleeper = null,
        private readonly int $maxPollAttempts = 60,
    ) {
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * 세션을 생성한다(POST /v1/sessions). 응답에 pickerUri·id·pollingConfig 가 포함된다.
     *
     * @return array<string, mixed>
     */
    public function createSession(string $accessToken): array
    {
        return $this->send('POST', self::BASE_URL . '/sessions', $accessToken, '{}');
    }

    /**
     * 세션 상태를 단건 조회한다(GET /v1/sessions/{id}).
     *
     * @return array<string, mixed>
     */
    public function getSession(string $accessToken, string $sessionId): array
    {
        return $this->send('GET', self::BASE_URL . '/sessions/' . rawurlencode($sessionId), $accessToken);
    }

    /**
     * mediaItemsSet=true 가 될 때까지 세션을 폴링한다.
     *
     * 대기 간격은 서버가 내려준 pollingConfig.pollInterval 을 준수하며,
     * maxPollAttempts 를 초과하면 예외를 던진다(무한 대기 방지).
     *
     * @return array<string, mixed> 준비 완료된 세션 상태.
     */
    public function pollUntilReady(string $accessToken, string $sessionId): array
    {
        for ($attempt = 0; $attempt < $this->maxPollAttempts; $attempt++) {
            $session = $this->getSession($accessToken, $sessionId);

            if (($session['mediaItemsSet'] ?? false) === true) {
                return $session;
            }

            $pollingConfig = $session['pollingConfig'] ?? [];
            $interval = is_array($pollingConfig) ? ($pollingConfig['pollInterval'] ?? null) : null;
            ($this->sleeper)($this->parsePollInterval(is_string($interval) ? $interval : null));
        }

        throw new RuntimeException('Picker 세션이 폴링 한도 내에 준비되지 않았습니다: session_id=' . $sessionId);
    }

    /**
     * 선택된 미디어 항목을 조회한다(GET /v1/mediaItems?sessionId=...).
     * 최대 매수(기본 10장)로 잘라낸다.
     *
     * @return list<array<string, mixed>>
     */
    public function listPickedMediaItems(string $accessToken, string $sessionId): array
    {
        $query = http_build_query([
            'sessionId' => $sessionId,
            'pageSize' => $this->maxItems,
        ]);

        $response = $this->send('GET', self::BASE_URL . '/mediaItems?' . $query, $accessToken);

        $items = $response['mediaItems'] ?? [];
        if (! is_array($items)) {
            return [];
        }

        /** @var list<array<string, mixed>> $normalized */
        $normalized = [];
        foreach (array_slice(array_values($items), 0, $this->maxItems) as $item) {
            if (is_array($item)) {
                $normalized[] = $item;
            }
        }

        return $normalized;
    }

    /**
     * 공통 HTTP 요청 실행 + JSON 디코딩. 4xx/5xx 는 예외로 변환한다(토큰 비노출).
     *
     * @return array<string, mixed>
     */
    private function send(string $method, string $url, string $accessToken, ?string $body = null): array
    {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept' => 'application/json',
            ],
            'http_errors' => false,
        ];

        if ($body !== null) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = $body;
        }

        $response = $this->client->request($method, $url, $options);

        return $this->handle($response, $method, $url);
    }

    /**
     * 응답 상태를 검증하고 JSON 바디를 배열로 디코딩한다.
     *
     * @return array<string, mixed>
     */
    private function handle(ResponseInterface $response, string $method, string $url): array
    {
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf(
                'Picker API 요청 실패: %s %s (status=%d)',
                $method,
                $this->stripQuery($url),
                $status,
            ));
        }

        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * "5s"·"1.500s" 형태의 Duration 문자열을 초(정수, 최소 1)로 변환한다.
     */
    private function parsePollInterval(?string $interval): int
    {
        if ($interval === null || $interval === '') {
            return self::DEFAULT_POLL_INTERVAL;
        }

        $seconds = (float) rtrim($interval, 's');
        if ($seconds <= 0.0) {
            return self::DEFAULT_POLL_INTERVAL;
        }

        return max(1, (int) ceil($seconds));
    }

    /**
     * 예외 메시지에 쿼리스트링(sessionId 등)이 새지 않도록 제거한다.
     */
    private function stripQuery(string $url): string
    {
        $pos = strpos($url, '?');

        return $pos === false ? $url : substr($url, 0, $pos);
    }
}
