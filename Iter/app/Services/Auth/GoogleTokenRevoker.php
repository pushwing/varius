<?php

declare(strict_types=1);

namespace App\Services\Auth;

use CodeIgniter\HTTP\CURLRequest;
use RuntimeException;

/**
 * Google OAuth2 토큰 폐기 엔드포인트 호출 구현.
 *
 * @see https://developers.google.com/identity/protocols/oauth2/web-server#tokenrevoke
 */
final class GoogleTokenRevoker implements TokenRevokerInterface
{
    private const REVOKE_ENDPOINT = 'https://oauth2.googleapis.com/revoke';

    public function __construct(
        private readonly CURLRequest $client,
    ) {
    }

    public function revoke(string $token): void
    {
        if ($token === '') {
            return;
        }

        // 외부 호출은 타임아웃을 반드시 건다(기본 5초). 실패는 예외로 전파해 호출측이 로깅한다.
        $response = $this->client->request('POST', self::REVOKE_ENDPOINT, [
            'form_params' => ['token' => $token],
            'timeout' => 5,
            'http_errors' => false,
        ]);

        $status = $response->getStatusCode();
        // 이미 폐기됐거나 유효하지 않은 토큰은 400 을 반환하므로 성공으로 간주한다.
        if ($status !== 200 && $status !== 400) {
            throw new RuntimeException('Google 토큰 폐기 실패: HTTP ' . $status);
        }
    }
}
