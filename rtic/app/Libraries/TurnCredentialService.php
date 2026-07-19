<?php

declare(strict_types=1);

namespace App\Libraries;

use Config\Turn as TurnConfig;

final class TurnCredentialService
{
    public function __construct(private readonly TurnConfig $config)
    {
    }

    /**
     * coturn의 use-auth-secret(TURN REST API) 방식에 맞는 단기 TTL credential을 발급한다.
     *
     * @return array{username: string, credential: string, ttl: int, realm: string}
     */
    public function issue(string $userId): array
    {
        $ttl      = $this->config->credentialTtl;
        $username = (string) (time() + $ttl) . ':' . $userId;

        return [
            'username'   => $username,
            'credential' => base64_encode(hash_hmac('sha1', $username, $this->secret(), true)),
            'ttl'        => $ttl,
            'realm'      => $this->config->realm,
        ];
    }

    private function secret(): string
    {
        if ($this->config->secret === '') {
            throw new \RuntimeException('turn.secret 이 .env에 설정되지 않았습니다.');
        }

        return $this->config->secret;
    }
}
