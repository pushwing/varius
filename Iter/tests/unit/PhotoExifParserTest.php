<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ingest\PhotoExifParser;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class PhotoExifParserTest extends CIUnitTestCase
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function exif(array $overrides = []): array
    {
        return $overrides + [
            'GPSLatitude' => ['37/1', '30/1', '0/1'],
            'GPSLatitudeRef' => 'N',
            'GPSLongitude' => ['127/1', '0/1', '0/1'],
            'GPSLongitudeRef' => 'E',
            'DateTimeOriginal' => '2019:07:18 22:55:29',
        ];
    }

    public function testParsesNorthEastCoordinatesAndTakenTime(): void
    {
        $result = (new PhotoExifParser())->parse($this->exif());

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(37.5, $result->lat, 0.0001);
        $this->assertEqualsWithDelta(127.0, $result->lng, 0.0001);
        // EXIF 촬영 시각은 카메라 로컬(KST 가정) — 저장 표준인 UTC 로 변환된다.
        $this->assertSame('2019-07-18 13:55:29', $result->takenAt);
    }

    public function testAppliesSouthAndWestRefsAsNegative(): void
    {
        $result = (new PhotoExifParser())->parse($this->exif([
            'GPSLatitudeRef' => 'S',
            'GPSLongitudeRef' => 'W',
        ]));

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(-37.5, $result->lat, 0.0001);
        $this->assertEqualsWithDelta(-127.0, $result->lng, 0.0001);
    }

    public function testConvertsDegreesMinutesSecondsToDecimal(): void
    {
        // 37° 33' 29.85" N = 37 + 33/60 + 29.85/3600 = 37.5583...
        $result = (new PhotoExifParser())->parse($this->exif([
            'GPSLatitude' => ['37/1', '33/1', '2985/100'],
        ]));

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(37.55829, $result->lat, 0.0001);
    }

    public function testReturnsNullWhenGpsMissing(): void
    {
        $result = (new PhotoExifParser())->parse([
            'DateTimeOriginal' => '2019:07:18 22:55:29',
        ]);

        $this->assertNull($result);
    }

    public function testReturnsNullWhenGpsMalformed(): void
    {
        $result = (new PhotoExifParser())->parse($this->exif([
            'GPSLatitude' => 'not-an-array',
        ]));

        $this->assertNull($result);
    }

    public function testReturnsNullWhenCoordinatesAreZero(): void
    {
        // (0,0) 은 위치 없음으로 간주(Takeout 파서와 동일한 취급).
        $result = (new PhotoExifParser())->parse($this->exif([
            'GPSLatitude' => ['0/1', '0/1', '0/1'],
            'GPSLongitude' => ['0/1', '0/1', '0/1'],
        ]));

        $this->assertNull($result);
    }

    public function testTakenAtIsNullWhenNoDateTimePresent(): void
    {
        $exif = $this->exif();
        unset($exif['DateTimeOriginal']);

        $result = (new PhotoExifParser())->parse($exif);

        $this->assertNotNull($result);
        $this->assertNull($result->takenAt);
    }

    public function testFallsBackToDateTimeWhenOriginalMissing(): void
    {
        $exif = $this->exif(['DateTime' => '2020:01:02 03:04:05']);
        unset($exif['DateTimeOriginal']);

        $result = (new PhotoExifParser())->parse($exif);

        $this->assertNotNull($result);
        // KST 2020-01-02 03:04:05 → UTC 로 -9시간(날짜 경계도 넘어간다).
        $this->assertSame('2020-01-01 18:04:05', $result->takenAt);
    }
}
