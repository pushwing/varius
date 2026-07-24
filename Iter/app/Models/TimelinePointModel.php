<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Ingest\TimelinePoint;
use CodeIgniter\Model;

/**
 * 위치기록 트랙 포인트 데이터 접근.
 */
class TimelinePointModel extends Model
{
    protected $table = 'timeline_points';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = [
        'user_id',
        'lat',
        'lng',
        'recorded_at',
        'created_at',
    ];
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = '';

    /**
     * 트랙 포인트를 저장한다. (user_id, recorded_at) 가 이미 있으면 건너뛴다(재업로드 idempotent).
     *
     * @param list<TimelinePoint> $points
     *
     * @return int 실제 삽입 건수
     */
    public function saveBatch(int $userId, array $points): int
    {
        if ($points === []) {
            return 0;
        }

        $times = array_map(static fn (TimelinePoint $p): string => $p->recordedAt, $points);

        // 들어온 범위의 기존 recorded_at 집합만 조회해 중복을 거른다.
        /** @var list<array{recorded_at: string}> $existingRows */
        $existingRows = $this->builder()
            ->select('recorded_at')
            ->where('user_id', $userId)
            ->where('recorded_at >=', min($times))
            ->where('recorded_at <=', max($times))
            ->get()->getResultArray();

        $seen = [];
        foreach ($existingRows as $row) {
            $seen[$row['recorded_at']] = true;
        }

        $rows = [];
        foreach ($points as $point) {
            if (isset($seen[$point->recordedAt])) {
                continue;
            }
            $seen[$point->recordedAt] = true; // 배치 내 중복도 한 번만

            $rows[] = [
                'user_id' => $userId,
                'lat' => $point->lat,
                'lng' => $point->lng,
                'recorded_at' => $point->recordedAt,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        return (int) $this->insertBatch($rows);
    }

    /**
     * UTC 시각 범위의 트랙 포인트를 시간 오름차순으로 조회한다(지도 트랙 렌더용).
     *
     * @return list<array{lat: float, lng: float}>
     */
    public function findTrackByUtcRange(int $userId, string $fromUtc, string $toUtc): array
    {
        /** @var list<array{lat: float|string, lng: float|string}> $rows */
        $rows = $this->builder()
            ->select('lat, lng')
            ->where('user_id', $userId)
            ->where('recorded_at >=', $fromUtc)
            ->where('recorded_at <=', $toUtc)
            ->orderBy('recorded_at', 'ASC')
            ->get()->getResultArray();

        return array_map(
            static fn (array $row): array => ['lat' => (float) $row['lat'], 'lng' => (float) $row['lng']],
            $rows,
        );
    }
}
