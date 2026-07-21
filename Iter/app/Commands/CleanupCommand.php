<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * 업로드 처리 잔존물 정리 커맨드(크론 주기 실행 권장).
 *
 * 치명적 오류로 남은 오래된 임시 디렉터리·zip 과 DB 참조 없는 고아 썸네일을 걷어낸다.
 *
 * 예) crontab: 매시간 실행
 *     0 * * * * cd /path/to/Iter && php spark iter:cleanup >> writable/logs/cleanup.log 2>&1
 */
class CleanupCommand extends BaseCommand
{
    protected $group = 'Iter';
    protected $name = 'iter:cleanup';
    protected $description = '오래된 업로드 임시물과 DB 참조 없는 고아 썸네일을 정리한다.';
    protected $usage = 'iter:cleanup [--max-age-hours=1]';

    /**
     * @var array<string, string>
     */
    protected $options = [
        '--max-age-hours' => '이 시간보다 오래된 임시물을 삭제한다(기본 1).',
    ];

    /**
     * @param list<string> $params
     */
    public function run(array $params): int
    {
        $hoursOption = $params['max-age-hours'] ?? CLI::getOption('max-age-hours');
        $hours = is_numeric($hoursOption) ? (float) $hoursOption : 1.0;

        /** @var \App\Services\StorageMaintenanceService $service */
        $service = service('storageMaintenance');

        $uploads = $service->pruneStaleUploads((int) round($hours * 3600));
        $thumbs = $service->pruneOrphanThumbnails();

        CLI::write(sprintf('정리 완료: 임시물 %d건, 고아 썸네일 %d건 삭제', $uploads, $thumbs), 'green');

        return EXIT_SUCCESS;
    }
}
