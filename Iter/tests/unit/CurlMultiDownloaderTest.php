<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Ingest\RecordingCurlMultiDownloader;

/**
 * @internal
 */
final class CurlMultiDownloaderTest extends CIUnitTestCase
{
    /**
     * 실제 네트워크 전송(executeBatch)을 시뮬레이션하는 다운로더를 만든다.
     * $succeed=false 면 전송 실패를 흉내낸다.
     */
    private function fakeDownloader(bool $succeed = true): RecordingCurlMultiDownloader
    {
        return new RecordingCurlMultiDownloader(sys_get_temp_dir(), $succeed);
    }

    public function testBuildsDownloadUrlAndAuthHeaderAndSkipsItemsWithoutBaseUrl(): void
    {
        $downloader = $this->fakeDownloader();

        $paths = $downloader->download([
            ['id' => 'a', 'baseUrl' => 'https://base/a'],
            ['id' => 'b'], // baseUrl 없음 → 제외
            ['id' => '', 'baseUrl' => 'https://base/none'], // id 없음 → 제외
        ], 'token123');

        try {
            // baseUrl 있는 'a' 만 작업으로 만들어진다.
            $this->assertArrayHasKey('a', $downloader->capturedJobs);
            $this->assertArrayNotHasKey('b', $downloader->capturedJobs);
            $this->assertCount(1, $downloader->capturedJobs);

            // baseUrl + "=d" 로 원본(EXIF 포함) 요청.
            $this->assertSame('https://base/a=d', $downloader->capturedJobs['a']['url']);
            $this->assertContains('Authorization: Bearer token123', $downloader->capturedJobs['a']['headers']);

            // 성공한 항목만 id→경로 맵으로 반환되고 파일이 존재한다.
            $this->assertArrayHasKey('a', $paths);
            $this->assertSame('bytes-of-a', file_get_contents($paths['a']));
        } finally {
            foreach ($paths as $p) {
                if (is_file($p)) {
                    unlink($p);
                }
            }
        }
    }

    public function testFailedTransferIsExcludedAndTempFileCleanedUp(): void
    {
        $downloader = $this->fakeDownloader(succeed: false);

        $paths = $downloader->download([['id' => 'a', 'baseUrl' => 'https://base/a']], 'token123');

        $this->assertSame([], $paths);
        // 실패한 임시 파일은 남지 않는다.
        $tmpPath = $downloader->capturedJobs['a']['tmpPath'];
        $this->assertFileDoesNotExist($tmpPath);
    }
}
