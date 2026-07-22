<?php

declare(strict_types=1);

namespace App\Services\Ingest;

/**
 * PHP exif_read_data() 기반 EXIF 리더.
 *
 * JPEG/TIFF 등 EXIF 를 담는 포맷에서만 데이터를 반환하며, 손상되거나
 * EXIF 가 없는 파일은 null 을 돌려준다(경고는 억제 — 호출측이 null 로 판단).
 */
final class NativeExifReader implements ExifReaderInterface
{
    public function read(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $exif = @exif_read_data($path, 'ANY_TAG', true);
        if (! is_array($exif)) {
            return null;
        }

        // exif_read_data(..., $arrayMap=true) 은 섹션별로 중첩 배열을 반환한다.
        // GPS/EXIF/IFD0 섹션의 태그를 평탄화해 PhotoExifParser 가 기대하는
        // 단일 배열(GPSLatitude, DateTimeOriginal 등)로 만든다.
        $flat = [];
        foreach ($exif as $section) {
            if (is_array($section)) {
                $flat = array_merge($flat, $section);
            }
        }

        return $flat === [] ? null : $flat;
    }
}
