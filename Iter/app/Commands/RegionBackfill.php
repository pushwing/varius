<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\PhotoLocationModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * 기존 photo_locations 행의 지역 코드 일괄 판별 — php spark region:backfill
 *
 * 좌표는 있으나 country_code 가 비어 있는 행을 id 커서로 순회하며 RegionResolver 로 채운다.
 * 바다 등 판별 불가 행은 null 로 남는다(재실행해도 커서가 전진해 무한 루프 없음).
 */
final class RegionBackfill extends BaseCommand
{
    protected $group = 'Iter';
    protected $name = 'region:backfill';
    protected $description = '좌표는 있고 지역 코드가 없는 photo_locations 를 일괄 판별한다.';

    private const BATCH_SIZE = 500;

    public function run(array $params): void
    {
        $model = model(PhotoLocationModel::class);
        $resolver = service('regionResolver');

        $afterId = 0;
        $scanned = 0;
        $resolved = 0;

        while (true) {
            $rows = $model->findUnresolvedBatch($afterId, self::BATCH_SIZE);
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $afterId = $row['id'];
                $scanned++;
                $region = $resolver->resolve($row['lat'], $row['lng']);
                if ($region['countryCode'] === null) {
                    continue; // 바다 등 — null 유지
                }
                $model->update($row['id'], [
                    'country_code' => $region['countryCode'],
                    'region_code' => $region['regionCode'],
                ]);
                $resolved++;
            }

            CLI::write("진행: {$scanned}건 스캔, {$resolved}건 판별");
        }

        CLI::write("완료 — 총 {$scanned}건 중 {$resolved}건에 지역 코드를 채웠습니다.", 'green');
    }
}
