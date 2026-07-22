<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class ShareLinkModel extends Model
{
    protected $table = 'share_links';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = '';

    /**
     * @var list<string>
     */
    protected $allowedFields = [
        'user_id',
        'share_date',
        'token',
    ];

    /**
     * 해당 날짜의 공유 링크를 반환한다(있으면 재사용, 없으면 새로 발급).
     *
     * 링크 재발급 방지 — 같은 날짜를 다시 공유해도 이미 퍼진 링크가 유지된다.
     */
    public function createOrGet(int $userId, string $date): string
    {
        $existing = $this->where('user_id', $userId)->where('share_date', $date)->first();
        if ($existing !== null) {
            return (string) $existing['token'];
        }

        $token = bin2hex(random_bytes(16));
        $this->insert([
            'user_id' => $userId,
            'share_date' => $date,
            'token' => $token,
        ]);

        return $token;
    }

    /**
     * 토큰으로 공유 링크(소유자·날짜)를 조회한다.
     *
     * @return array{user_id: int, share_date: string}|null
     */
    public function findByToken(string $token): ?array
    {
        $row = $this->select('user_id, share_date')->where('token', $token)->first();
        if ($row === null) {
            return null;
        }

        return [
            'user_id' => (int) $row['user_id'],
            'share_date' => (string) $row['share_date'],
        ];
    }
}
