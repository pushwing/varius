<?php

declare(strict_types=1);

namespace App\Services\Ingest;

/**
 * 사진 파일에서 EXIF 배열을 읽는 경계.
 *
 * 실제 파일 I/O(NativeExifReader → exif_read_data)와 테스트 대역을 분리해,
 * PlainZipIngestService 를 실제 EXIF 픽스처 없이 단위 테스트할 수 있게 한다.
 */
interface ExifReaderInterface
{
    /**
     * @return array<string, mixed>|null EXIF 를 읽지 못하면 null
     */
    public function read(string $path): ?array;
}
