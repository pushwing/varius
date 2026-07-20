<?php

declare(strict_types=1);

namespace App\Services\Ingest;

/**
 * Picker 로 선택된 mediaItems 의 원본을 내려받아 로컬 임시 파일로 저장한다.
 *
 * baseUrl 은 약 60분 후 만료되므로 발급 즉시 처리한다. 실제 병렬 다운로드(curl_multi)
 * 구현은 후속 이슈에서 이 인터페이스를 구현해 제공한다.
 */
interface MediaItemDownloaderInterface
{
    /**
     * @param list<array<string, mixed>> $mediaItems 각 항목은 최소 id·baseUrl 을 포함한다
     * @param string                     $accessToken 원본 다운로드용 액세스 토큰
     *
     * @return array<string, string> mediaItemId => 로컬 임시 파일 경로
     */
    public function download(array $mediaItems, string $accessToken): array;
}
