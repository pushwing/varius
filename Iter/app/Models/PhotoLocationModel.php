<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Ingest\PhotoLocation;
use CodeIgniter\Model;

class PhotoLocationModel extends Model
{
    protected $table = 'photo_locations';
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
        'source_item_id',
        'lat',
        'lng',
        'thumbnail_path',
        'taken_at',
    ];

    /**
     * 사용자 좌표를 촬영 시각 오름차순으로 조회한다(동선 조합용, 필요 컬럼만).
     *
     * @return list<array<string, mixed>>
     */
    public function findByUserOrdered(int $userId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->select('id, source_item_id, lat, lng, thumbnail_path, taken_at')
            ->where('user_id', $userId)
            ->orderBy('taken_at', 'ASC')
            ->findAll();

        return $rows;
    }

    /**
     * 촬영 시각(UTC) 범위 내 사용자 좌표를 오름차순으로 조회한다(시간별 동선용).
     *
     * @return list<array<string, mixed>>
     */
    public function findByUserBetween(int $userId, string $startUtc, string $endUtc): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->select('id, source_item_id, lat, lng, thumbnail_path, taken_at')
            ->where('user_id', $userId)
            ->where('taken_at >=', $startUtc)
            ->where('taken_at <=', $endUtc)
            ->orderBy('taken_at', 'ASC')
            ->findAll();

        return $rows;
    }

    /**
     * DB 에 저장된 모든(비어있지 않은) 썸네일 경로를 반환한다(고아 썸네일 정리용).
     *
     * @return list<string>
     */
    public function allThumbnailPaths(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->select('thumbnail_path')
            ->where('thumbnail_path IS NOT NULL')
            ->where('thumbnail_path !=', '')
            ->findAll();

        return array_values(array_map(static fn (array $row): string => (string) $row['thumbnail_path'], $rows));
    }

    /**
     * 좌표 레코드가 이 사용자 소유일 때만 행을 반환한다(다른 사용자 접근 방지).
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
     * 좌표 레코드가 이 사용자 소유일 때만 썸네일 경로를 반환한다(다른 사용자 열람 방지).
     */
    public function thumbnailPathFor(int $id, int $userId): ?string
    {
        $row = $this->select('thumbnail_path')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if ($row === null || $row['thumbnail_path'] === null) {
            return null;
        }

        return (string) $row['thumbnail_path'];
    }

    /**
     * 동선 좌표들을 저장한다. 사용자당 이미 적재된 source_item_id 는 건너뛴다(idempotent).
     *
     * @param list<PhotoLocation> $locations
     *
     * @return int 실제로 삽입된 건수
     */
    public function saveMany(int $userId, array $locations): int
    {
        if ($locations === []) {
            return 0;
        }

        $existing = $this->existingSourceItemIds($userId, array_map(
            static fn (PhotoLocation $l): string => $l->mediaItemId,
            $locations,
        ));

        $rows = [];
        foreach ($locations as $location) {
            if (isset($existing[$location->mediaItemId])) {
                continue; // 중복 제외
            }
            // 같은 배치 안의 중복도 한 번만.
            $existing[$location->mediaItemId] = true;

            $rows[] = [
                'user_id' => $userId,
                'source_item_id' => $location->mediaItemId,
                'lat' => $location->lat,
                'lng' => $location->lng,
                'thumbnail_path' => $location->thumbnailPath,
                'taken_at' => $location->takenAt,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        return (int) $this->insertBatch($rows);
    }

    /**
     * 주어진 source_item_id 중 이미 저장된 것을 집합(키=id)으로 반환한다.
     *
     * @param list<string> $sourceItemIds
     *
     * @return array<string, true>
     */
    private function existingSourceItemIds(int $userId, array $sourceItemIds): array
    {
        if ($sourceItemIds === []) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->select('source_item_id')
            ->where('user_id', $userId)
            ->whereIn('source_item_id', array_values(array_unique($sourceItemIds)))
            ->findAll();

        $set = [];
        foreach ($rows as $row) {
            $set[(string) $row['source_item_id']] = true;
        }

        return $set;
    }
}
