<?php

declare(strict_types=1);

namespace App\Libraries;

use Config\Jwt as JwtConfig;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

final class JwtTokenService
{
    public function __construct(private readonly JwtConfig $config)
    {
    }

    /**
     * @return array{token: string, expiresIn: int, room: string}
     */
    public function issueRoomEntryToken(int $userId, string $email): array
    {
        $now = time();
        $ttl = $this->config->roomEntryTtl;

        $token = JWT::encode([
            'iss'   => 'rtic',
            'sub'   => $userId,
            'email' => $email,
            'room'  => $this->config->roomName,
            'iat'   => $now,
            'exp'   => $now + $ttl,
        ], $this->secret(), $this->config->algo);

        return ['token' => $token, 'expiresIn' => $ttl, 'room' => $this->config->roomName];
    }

    /**
     * @throws \Exception 토큰이 유효하지 않거나 만료된 경우
     */
    public function decode(string $token): object
    {
        return JWT::decode($token, new Key($this->secret(), $this->config->algo));
    }

    private function secret(): string
    {
        if ($this->config->secret === '') {
            throw new \RuntimeException('jwt.secret 이 .env에 설정되지 않았습니다.');
        }

        return $this->config->secret;
    }
}
