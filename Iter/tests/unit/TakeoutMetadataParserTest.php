<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ingest\TakeoutMetadataParser;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TakeoutMetadataParserTest extends CIUnitTestCase
{
    private TakeoutMetadataParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new TakeoutMetadataParser();
    }

    public function testParsesGeoDataCoordinatesAndTimestamp(): void
    {
        $location = $this->parser->parse([
            'geoData' => ['latitude' => 37.5665, 'longitude' => 126.9780, 'altitude' => 10.0],
            'photoTakenTime' => ['timestamp' => '1563490529'],
        ]);

        $this->assertNotNull($location);
        $this->assertEqualsWithDelta(37.5665, $location->lat, 0.0001);
        $this->assertEqualsWithDelta(126.9780, $location->lng, 0.0001);
        $this->assertSame('2019-07-18 22:55:29', $location->takenAt);
    }

    public function testTimestampIsStoredAsUtcRegardlessOfDefaultTimezone(): void
    {
        // 서버 기본 타임존이 무엇이든 epoch → UTC 문자열로 저장돼야 한다(저장 표준 = UTC).
        $original = date_default_timezone_get();
        date_default_timezone_set('Asia/Seoul');

        try {
            $location = $this->parser->parse([
                'geoData' => ['latitude' => 37.5665, 'longitude' => 126.9780],
                'photoTakenTime' => ['timestamp' => '1563490529'],
            ]);
        } finally {
            date_default_timezone_set($original);
        }

        $this->assertNotNull($location);
        $this->assertSame('2019-07-18 22:55:29', $location->takenAt);
    }

    public function testFallsBackToGeoDataExifWhenGeoDataIsZero(): void
    {
        $location = $this->parser->parse([
            'geoData' => ['latitude' => 0.0, 'longitude' => 0.0],
            'geoDataExif' => ['latitude' => 35.1796, 'longitude' => 129.0756],
            'photoTakenTime' => ['timestamp' => '1563490529'],
        ]);

        $this->assertNotNull($location);
        $this->assertEqualsWithDelta(35.1796, $location->lat, 0.0001);
        $this->assertEqualsWithDelta(129.0756, $location->lng, 0.0001);
    }

    public function testReturnsLocationWithNullCoordsWhenBothGeoDataAndGeoDataExifAreZero(): void
    {
        // 좌표는 위치 없음으로 판정되지만, 촬영 시각이 있으면 사진 자체는 살려야 한다
        // (GPS 없는 사진도 시간표에는 노출).
        $location = $this->parser->parse([
            'geoData' => ['latitude' => 0.0, 'longitude' => 0.0],
            'geoDataExif' => ['latitude' => 0.0, 'longitude' => 0.0],
            'photoTakenTime' => ['timestamp' => '1563490529'],
        ]);

        $this->assertNotNull($location);
        $this->assertNull($location->lat);
        $this->assertNull($location->lng);
        $this->assertSame('2019-07-18 22:55:29', $location->takenAt);
    }

    public function testReturnsLocationWithNullCoordsWhenGeoDataFieldsMissing(): void
    {
        $location = $this->parser->parse(['photoTakenTime' => ['timestamp' => '1563490529']]);

        $this->assertNotNull($location);
        $this->assertNull($location->lat);
        $this->assertNull($location->lng);
        $this->assertSame('2019-07-18 22:55:29', $location->takenAt);
    }

    public function testReturnsNullWhenNoCoordinatesAndNoTimestamp(): void
    {
        // 좌표도 촬영 시각도 없으면 동선에 쓸 수 없어 완전히 버려진다.
        $this->assertNull($this->parser->parse([]));
    }

    public function testTakenAtIsNullWhenTimestampMissing(): void
    {
        $location = $this->parser->parse([
            'geoData' => ['latitude' => 37.5665, 'longitude' => 126.9780],
        ]);

        $this->assertNotNull($location);
        $this->assertNull($location->takenAt);
    }

    public function testReturnsLocationWithNullCoordsWhenLatitudeIsNonNumeric(): void
    {
        $location = $this->parser->parse([
            'geoData' => ['latitude' => 'invalid', 'longitude' => 126.9780],
            'photoTakenTime' => ['timestamp' => '1563490529'],
        ]);

        $this->assertNotNull($location);
        $this->assertNull($location->lat);
        $this->assertNull($location->lng);
    }
}
