<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class OAuthTokenModel extends Model
{
    protected $table = 'oauth_tokens';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;
    protected $createdField = '';
    protected $updatedField = 'updated_at';

    /**
     * @var list<string>
     */
    protected $allowedFields = [
        'user_id',
        'refresh_token_encrypted',
        'access_token_encrypted',
        'expires_at',
    ];

    /**
     * user_id 기준 단건 조회.
     *
     * @return array<string, mixed>|null
     */
    public function findByUserId(int $userId): ?array
    {
        /** @var array<string, mixed>|null $row */
        $row = $this->where('user_id', $userId)->first();

        return $row;
    }

    /**
     * user_id 기준으로 토큰 레코드를 upsert 한다.
     *
     * @param array<string, mixed> $data
     */
    public function upsertByUserId(int $userId, array $data): void
    {
        $existing = $this->findByUserId($userId);

        if ($existing !== null) {
            $this->update($existing['id'], $data);

            return;
        }

        $this->insert(['user_id' => $userId] + $data);
    }
}
