<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ingest\ExifGpsParser;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ExifGpsParserTest extends CIUnitTestCase
{
    private ExifGpsParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ExifGpsParser();
    }

    /**
     * exif_read_data(..., true) 형태를 흉내낸 EXIF 배열.
     *
     * @param list<string> $lat
     * @param list<string> $lng
     *
     * @return array<string, mixed>
     */
    private function exif(array $lat, string $latRef, array $lng, string $lngRef, ?string $dateTime = '2024:03:15 09:30:00'): array
    {
        $gps = [
            'GPSLatitude' => $lat,
            'GPSLatitudeRef' => $latRef,
            'GPSLongitude' => $lng,
            'GPSLongitudeRef' => $lngRef,
        ];

        $exif = ['GPS' => $gps];
        if ($dateTime !== null) {
            $exif['EXIF'] = ['DateTimeOriginal' => $dateTime];
        }

        return $exif;
    }

    public function testParsesDmsToDecimalForNorthEast(): void
    {
        // 37° 30' 0" N, 127° 0' 0" E → 37.5, 127.0
        $loc = $this->parser->parse($this->exif(['37/1', '30/1', '0/1'], 'N', ['127/1', '0/1', '0/1'], 'E'));

        $this->assertNotNull($loc);
        $this->assertEqualsWithDelta(37.5, $loc->lat, 0.0001);
        $this->assertEqualsWithDelta(127.0, $loc->lng, 0.0001);
    }

    public function testAppliesNegativeSignForSouthWest(): void
    {
        // 남반구·서경은 음수여야 한다.
        $loc = $this->parser->parse($this->exif(['33/1', '51/1', '54/1'], 'S', ['151/1', '12/1', '36/1'], 'W'));

        $this->assertNotNull($loc);
        $this->assertLessThan(0, $loc->lat);
        $this->assertLessThan(0, $loc->lng);
        $this->assertEqualsWithDelta(-33.865, $loc->lat, 0.001);
        $this->assertEqualsWithDelta(-151.21, $loc->lng, 0.001);
    }

    public function testHandlesRationalSecondsWithDenominator(): void
    {
        // 초가 "3600/100"(=36.0)로 오는 분수 표기도 처리한다.
        $loc = $this->parser->parse($this->exif(['37/1', '0/1', '3600/100'], 'N', ['127/1', '0/1', '0/1'], 'E'));

        $this->assertNotNull($loc);
        // 37 + 0/60 + 36/3600 = 37.01
        $this->assertEqualsWithDelta(37.01, $loc->lat, 0.0001);
    }

    public function testParsesDateTimeOriginalToStandardFormat(): void
    {
        $loc = $this->parser->parse($this->exif(['37/1', '30/1', '0/1'], 'N', ['127/1', '0/1', '0/1'], 'E', '2024:03:15 09:30:00'));

        $this->assertNotNull($loc);
        $this->assertSame('2024-03-15 09:30:00', $loc->takenAt);
    }

    public function testTakenAtIsNullWhenNoDateTime(): void
    {
        $loc = $this->parser->parse($this->exif(['37/1', '30/1', '0/1'], 'N', ['127/1', '0/1', '0/1'], 'E', null));

        $this->assertNotNull($loc);
        $this->assertNull($loc->takenAt);
    }

    public function testReturnsNullWhenNoGpsData(): void
    {
        $this->assertNull($this->parser->parse(['EXIF' => ['DateTimeOriginal' => '2024:03:15 09:30:00']]));
    }

    public function testReturnsNullWhenGpsIncomplete(): void
    {
        // 위도만 있고 경도가 없으면 좌표로 쓸 수 없다.
        $this->assertNull($this->parser->parse([
            'GPS' => ['GPSLatitude' => ['37/1', '30/1', '0/1'], 'GPSLatitudeRef' => 'N'],
        ]));
    }
}
