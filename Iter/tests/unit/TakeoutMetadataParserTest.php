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

    public function testReturnsNullWhenBothGeoDataAndGeoDataExifAreZero(): void
    {
        $location = $this->parser->parse([
            'geoData' => ['latitude' => 0.0, 'longitude' => 0.0],
            'geoDataExif' => ['latitude' => 0.0, 'longitude' => 0.0],
            'photoTakenTime' => ['timestamp' => '1563490529'],
        ]);

        $this->assertNull($location);
    }

    public function testReturnsNullWhenGeoDataFieldsMissing(): void
    {
        $this->assertNull($this->parser->parse(['photoTakenTime' => ['timestamp' => '1563490529']]));
    }

    public function testTakenAtIsNullWhenTimestampMissing(): void
    {
        $location = $this->parser->parse([
            'geoData' => ['latitude' => 37.5665, 'longitude' => 126.9780],
        ]);

        $this->assertNotNull($location);
        $this->assertNull($location->takenAt);
    }

    public function testReturnsNullWhenLatitudeIsNonNumeric(): void
    {
        $location = $this->parser->parse([
            'geoData' => ['latitude' => 'invalid', 'longitude' => 126.9780],
        ]);

        $this->assertNull($location);
    }
}
