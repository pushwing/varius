<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class TripShareLinkModel extends Model
{
    protected $table = 'trip_share_links';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = '';

    /**
     * @var list<string>
     */
    protected $allowedFields = [
        'trip_id',
        'token',
    ];

    /**
     * 해당 여행의 공유 링크를 반환한다(있으면 재사용, 없으면 새로 발급).
     *
     * 링크 재발급 방지 — 같은 여행을 다시 공유해도 이미 퍼진 링크가 유지된다.
     */
    public function createOrGet(int $tripId): string
    {
        $existing = $this->where('trip_id', $tripId)->first();
        if ($existing !== null) {
            return (string) $existing['token'];
        }

        $token = bin2hex(random_bytes(16));
        $this->insert([
            'trip_id' => $tripId,
            'token' => $token,
        ]);

        return $token;
    }

    /**
     * 토큰으로 여행 id 를 조회한다.
     */
    public function findByToken(string $token): ?int
    {
        $row = $this->select('trip_id')->where('token', $token)->first();

        return $row === null ? null : (int) $row['trip_id'];
    }
}
