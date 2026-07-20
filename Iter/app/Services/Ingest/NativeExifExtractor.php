<?php

declare(strict_types=1);

namespace App\Services\Ingest;

/**
 * PHP 내장 exif_read_data 기반 추출기(기본 구현).
 *
 * 파일 읽기·EXIF 디코딩만 담당하고, 실제 좌표·시각 해석은 순수 파서(ExifGpsParser)에 위임한다.
 * HEIC 등 내장 함수가 읽지 못하는 포맷은 이 인터페이스의 다른 구현으로 대체한다.
 */
final class NativeExifExtractor implements ExifExtractorInterface
{
    public function __construct(
        private readonly ExifGpsParser $parser = new ExifGpsParser(),
    ) {
    }

    public function extract(string $filePath): ?ExifLocation
    {
        if (! is_file($filePath)) {
            return null;
        }

        // exif_read_data 는 EXIF 가 없거나 손상된 파일에 E_WARNING 을 뱉는다(@ 억제 금지).
        // 경고를 무시하되 실패는 false 반환으로 판정한다.
        set_error_handler(static fn (): bool => true);

        try {
            $exif = exif_read_data($filePath, 'ANY_TAG', true);
        } finally {
            restore_error_handler();
        }

        if (! is_array($exif)) {
            return null;
        }

        return $this->parser->parse($exif);
    }
}
