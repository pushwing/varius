<?php

declare(strict_types=1);

namespace Tests\Support\Ingest;

use App\Services\Ingest\CurlMultiDownloader;

/**
 * 네트워크 없이 CurlMultiDownloader 를 검증하기 위한 테스트 더블.
 *
 * 실제 전송(executeBatch)만 시뮬레이션하고 구성된 작업(capturedJobs)을 노출한다.
 */
final class RecordingCurlMultiDownloader extends CurlMultiDownloader
{
    /**
     * @var array<string, array{url: string, headers: list<string>, tmpPath: string}>
     */
    public array $capturedJobs = [];

    public function __construct(string $tempDir, private readonly bool $succeed = true)
    {
        parent::__construct($tempDir);
    }

    /**
     * @param array<string, array{url: string, headers: list<string>, tmpPath: string}> $jobs
     *
     * @return array<string, bool>
     */
    protected function executeBatch(array $jobs): array
    {
        $this->capturedJobs = $jobs;

        $results = [];
        foreach ($jobs as $id => $job) {
            if ($this->succeed) {
                file_put_contents($job['tmpPath'], 'bytes-of-' . $id);
                $results[$id] = true;
            } else {
                $results[$id] = false;
            }
        }

        return $results;
    }
}
