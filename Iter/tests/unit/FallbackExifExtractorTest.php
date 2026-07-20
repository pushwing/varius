<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ingest\ExifExtractorInterface;
use App\Services\Ingest\ExifLocation;
use App\Services\Ingest\FallbackExifExtractor;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class FallbackExifExtractorTest extends CIUnitTestCase
{
    private function extractor(?ExifLocation $result): ExifExtractorInterface
    {
        $mock = $this->createMock(ExifExtractorInterface::class);
        $mock->method('extract')->willReturn($result);

        return $mock;
    }

    public function testUsesPrimaryResultWhenAvailable(): void
    {
        $primary = new ExifLocation(37.5, 127.0, '2024-03-15 09:00:00');

        $secondary = $this->createMock(ExifExtractorInterface::class);
        $secondary->expects($this->never())->method('extract'); // 1차가 성공하면 2차는 호출하지 않는다.

        $result = (new FallbackExifExtractor($this->extractor($primary), $secondary))->extract('/fake/a.jpg');

        $this->assertSame($primary, $result);
    }

    public function testFallsBackToSecondaryWhenPrimaryReturnsNull(): void
    {
        $fallback = new ExifLocation(1.0, 2.0, null);

        $result = (new FallbackExifExtractor($this->extractor(null), $this->extractor($fallback)))
            ->extract('/fake/a.heic');

        $this->assertSame($fallback, $result);
    }

    public function testReturnsNullWhenBothFail(): void
    {
        $result = (new FallbackExifExtractor($this->extractor(null), $this->extractor(null)))
            ->extract('/fake/a.heic');

        $this->assertNull($result);
    }
}
