<?php

declare(strict_types=1);

namespace App\Libraries;

use Config\LiveKit as LiveKitConfig;
use Firebase\JWT\JWT;

final class LiveKitAccessTokenService
{
    public function __construct(private readonly LiveKitConfig $config)
    {
    }

    /**
     * LiveKit 액세스 토큰(JWT, HS256)을 발급한다. VideoGrant는 클라이언트가
     * 지정된 room에 입장해 오디오/데이터 채널을 송수신할 수 있는 권한만 부여한다.
     *
     * @return array{token: string, url: string, room: string, expiresIn: int}
     */
    public function issue(string $identity): array
    {
        $now = time();
        $ttl = $this->config->tokenTtl;

        $token = JWT::encode([
            'iss' => $this->apiKey(),
            'sub' => $identity,
            'nbf' => $now,
            'exp' => $now + $ttl,
            'video' => [
                'room'           => $this->config->roomName,
                'roomJoin'       => true,
                'canPublish'     => true,
                'canSubscribe'   => true,
                'canPublishData' => true,
            ],
        ], $this->apiSecret(), 'HS256');

        return [
            'token'     => $token,
            'url'       => $this->config->url,
            'room'      => $this->config->roomName,
            'expiresIn' => $ttl,
        ];
    }

    private function apiKey(): string
    {
        if ($this->config->apiKey === '') {
            throw new \RuntimeException('livekit.apiKey 가 .env에 설정되지 않았습니다.');
        }

        return $this->config->apiKey;
    }

    private function apiSecret(): string
    {
        if ($this->config->apiSecret === '') {
            throw new \RuntimeException('livekit.apiSecret 이 .env에 설정되지 않았습니다.');
        }

        return $this->config->apiSecret;
    }
}
