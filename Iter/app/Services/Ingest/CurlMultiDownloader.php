<?php

declare(strict_types=1);

namespace App\Services\Ingest;

use RuntimeException;

/**
 * curl_multi 기반 원본 병렬 다운로더.
 *
 * baseUrl + "=d" 로 EXIF 포함 원본을 내려받아 웹 루트 밖 임시 파일에 저장하고
 * mediaItemId → 경로 맵을 돌려준다. 요청당 10장 제한 덕에 큐 없이 동기 병렬 처리한다.
 * baseUrl 은 약 60분 후 만료되므로 발급 즉시 호출해야 한다.
 *
 * 실제 전송(executeBatch)만 I/O 이고 나머지(작업 구성·경로 관리)는 순수하므로,
 * 테스트에서는 executeBatch 를 오버라이드해 네트워크 없이 검증한다.
 */
class CurlMultiDownloader implements MediaItemDownloaderInterface
{
    private readonly string $tempDir;

    public function __construct(
        ?string $tempDir = null,
        private readonly int $timeoutSeconds = 30,
    ) {
        $this->tempDir = $tempDir ?? WRITEPATH . 'uploads';
    }

    public function download(array $mediaItems, string $accessToken): array
    {
        $jobs = $this->buildJobs($mediaItems, $accessToken);
        if ($jobs === []) {
            return [];
        }

        $results = $this->executeBatch($jobs);

        $paths = [];
        foreach ($jobs as $id => $job) {
            $tmpPath = $job['tmpPath'];
            if (($results[$id] ?? false) === true && is_file($tmpPath) && filesize($tmpPath) > 0) {
                $paths[$id] = $tmpPath;

                continue;
            }

            // 실패·빈 파일은 남기지 않는다.
            if (is_file($tmpPath)) {
                unlink($tmpPath);
            }
        }

        return $paths;
    }

    /**
     * mediaItems 에서 다운로드 작업을 구성한다. baseUrl·id 가 없는 항목은 제외한다.
     *
     * @param list<array<string, mixed>> $mediaItems
     *
     * @return array<string, array{url: string, headers: list<string>, tmpPath: string}>
     */
    protected function buildJobs(array $mediaItems, string $accessToken): array
    {
        $this->ensureTempDir();

        $jobs = [];
        foreach ($mediaItems as $item) {
            $id = isset($item['id']) ? (string) $item['id'] : '';
            $baseUrl = isset($item['baseUrl']) && is_string($item['baseUrl']) ? $item['baseUrl'] : '';
            if ($id === '' || $baseUrl === '') {
                continue;
            }

            $jobs[$id] = [
                'url' => $baseUrl . '=d', // EXIF 포함 원본
                'headers' => ['Authorization: Bearer ' . $accessToken],
                'tmpPath' => $this->tempPath(),
            ];
        }

        return $jobs;
    }

    /**
     * curl_multi 로 모든 작업을 병렬 전송하고, 각 작업의 tmpPath 에 바디를 기록한다.
     *
     * @param array<string, array{url: string, headers: list<string>, tmpPath: string}> $jobs
     *
     * @return array<string, bool> mediaItemId => 성공 여부
     */
    protected function executeBatch(array $jobs): array
    {
        $multi = curl_multi_init();
        $handles = [];
        $files = [];

        foreach ($jobs as $id => $job) {
            $fp = fopen($job['tmpPath'], 'wb');
            if ($fp === false) {
                continue;
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $job['url'],
                CURLOPT_HTTPHEADER => $job['headers'],
                CURLOPT_FILE => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_FAILONERROR => true,
            ]);

            curl_multi_add_handle($multi, $ch);
            $handles[$id] = $ch;
            $files[$id] = $fp;
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $id => $ch) {
            $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $errno = curl_errno($ch);
            $results[$id] = $errno === 0 && $httpCode >= 200 && $httpCode < 300;

            curl_multi_remove_handle($multi, $ch);
            fclose($files[$id]);
        }

        curl_multi_close($multi);

        return $results;
    }

    /**
     * 임시 파일 경로를 만든다(빈 파일 생성).
     */
    private function tempPath(): string
    {
        $path = tempnam($this->tempDir, 'ingest_');
        if ($path === false) {
            throw new RuntimeException('임시 파일 생성에 실패했습니다: ' . $this->tempDir);
        }

        return $path;
    }

    private function ensureTempDir(): void
    {
        if (! is_dir($this->tempDir) && ! mkdir($this->tempDir, 0755, true) && ! is_dir($this->tempDir)) {
            throw new RuntimeException('임시 디렉토리를 만들 수 없습니다: ' . $this->tempDir);
        }
    }
}
