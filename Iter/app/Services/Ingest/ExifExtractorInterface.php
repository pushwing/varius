<?php

declare(strict_types=1);

namespace App\Services\Ingest;

/**
 * 이미지 파일에서 GPS 좌표·촬영 시각을 추출한다.
 *
 * 기본 구현은 네이티브 exif_read_data(NativeExifExtractor).
 * HEIC 등 예외 포맷은 exiftool 등을 쓰는 별도 구현으로 교체할 수 있도록 인터페이스로 둔다.
 */
interface ExifExtractorInterface
{
    /**
     * @param string $filePath 로컬 이미지 파일 경로
     *
     * @return ExifLocation|null GPS 좌표가 없으면 null
     */
    public function extract(string $filePath): ?ExifLocation;
}
