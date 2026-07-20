<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ingest\ExifToolExtractor;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ExifToolExtractorTest extends CIUnitTestCase
{
    /**
     * shell_exec 시뮬레이션을 오버라이드한 추출기를 만든다(exiftool 바이너리 불필요).
     */
    private function fakeExtractor(?string $rawJson): ExifToolExtractor
    {
        return new class ($rawJson) extends ExifToolExtractor {
            public function __construct(private readonly ?string $rawJson)
            {
            }

            protected function runExiftool(string $filePath): ?string
            {
                return $this->rawJson;
            }
        };
    }

    public function testExtractsNumericGpsAndDateTime(): void
    {
        $json = json_encode([[
            'GPSLatitude' => 37.5,
            'GPSLongitude' => 127.0,
            'DateTimeOriginal' => '2024:03:15 09:30:00',
        ]]);

        $loc = $this->fakeExtractor($json)->extract('/fake/photo.heic');

        $this->assertNotNull($loc);
        $this->assertEqualsWithDelta(37.5, $loc->lat, 0.0001);
        $this->assertEqualsWithDelta(127.0, $loc->lng, 0.0001);
        $this->assertSame('2024-03-15 09:30:00', $loc->takenAt);
    }

    public function testAppliesSouthWestSignsWhenNegative(): void
    {
        // exiftool -n 모드는 남/서반구를 이미 음수로 준다.
        $json = json_encode([[
            'GPSLatitude' => -33.865,
            'GPSLongitude' => -151.21,
        ]]);

        $loc = $this->fakeExtractor($json)->extract('/fake/photo.heic');

        $this->assertNotNull($loc);
        $this->assertLessThan(0, $loc->lat);
        $this->assertLessThan(0, $loc->lng);
    }

    public function testReturnsNullWhenNoGpsFields(): void
    {
        $json = json_encode([['DateTimeOriginal' => '2024:03:15 09:30:00']]);

        $this->assertNull($this->fakeExtractor($json)->extract('/fake/photo.heic'));
    }

    public function testReturnsNullWhenExiftoolUnavailableOrFails(): void
    {
        // 바이너리 부재·실행 실패는 null(폴백 없음)로 처리한다.
        $this->assertNull($this->fakeExtractor(null)->extract('/fake/photo.heic'));
    }

    public function testReturnsNullOnMalformedJson(): void
    {
        $this->assertNull($this->fakeExtractor('not json')->extract('/fake/photo.heic'));
    }

    public function testTakenAtIsNullWhenDateTimeMissing(): void
    {
        $json = json_encode([['GPSLatitude' => 37.5, 'GPSLongitude' => 127.0]]);

        $loc = $this->fakeExtractor($json)->extract('/fake/photo.heic');

        $this->assertNotNull($loc);
        $this->assertNull($loc->takenAt);
    }
}
