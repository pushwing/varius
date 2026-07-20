<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ingest\NativeExifExtractor;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class NativeExifExtractorTest extends CIUnitTestCase
{
    public function testReturnsNullForNonexistentFile(): void
    {
        $extractor = new NativeExifExtractor();

        $this->assertNull($extractor->extract('/no/such/file.jpg'));
    }

    public function testReturnsNullForNonImageFileWithoutEmittingWarnings(): void
    {
        // EXIF 가 없는 텍스트 파일에서도 경고 없이 null 을 돌려줘야 한다.
        $path = tempnam(sys_get_temp_dir(), 'exif_test_');
        $this->assertIsString($path);
        file_put_contents($path, 'not an image');

        try {
            $extractor = new NativeExifExtractor();
            $this->assertNull($extractor->extract($path));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
