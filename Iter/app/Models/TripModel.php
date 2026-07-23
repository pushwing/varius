<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class TripModel extends Model
{
    protected $table = 'trips';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;

    /**
     * @var list<string>
     */
    protected $allowedFields = [
        'user_id',
        'title',
        'body',
        'start_date',
        'end_date',
        'cover_photo_id',
    ];

    /**
     * 사용자의 여행을 최신 시작일순으로 조회한다.
     *
     * @return list<array<string, mixed>>
     */
    public function findByUserOrdered(int $userId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->where('user_id', $userId)
            ->orderBy('start_date', 'DESC')
            ->findAll();

        return $rows;
    }

    /**
     * 여행이 이 사용자 소유일 때만 반환한다(다른 사용자 접근 방지).
     *
     * @return array<string, mixed>|null
     */
    public function findOwned(int $id, int $userId): ?array
    {
        /** @var array<string, mixed>|null $row */
        $row = $this->where('id', $id)
            ->where('user_id', $userId)
            ->first();

        return $row;
    }

    /**
     * 주어진 기간이 이 사용자의 기존 여행과 겹치는지 확인한다(표준 구간 겹침 공식).
     *
     * @param int|null $excludeId 수정 시 자기 자신을 제외하기 위한 여행 id
     */
    public function overlaps(int $userId, string $startDate, string $endDate, ?int $excludeId = null): bool
    {
        $builder = $this->where('user_id', $userId)
            ->where('start_date <=', $endDate)
            ->where('end_date >=', $startDate);

        if ($excludeId !== null) {
            $builder->where('id !=', $excludeId);
        }

        return $builder->countAllResults() > 0;
    }

    /**
     * 그 해(KST)와 겹치는 여행을 조회한다(연간 통계용) — start_date 오름차순.
     *
     * @return list<array<string, mixed>>
     */
    public function findByUserInYear(int $userId, int $year): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->where('user_id', $userId)
            ->where('start_date <=', $year . '-12-31')
            ->where('end_date >=', $year . '-01-01')
            ->orderBy('start_date', 'ASC')
            ->findAll();

        return $rows;
    }
}
