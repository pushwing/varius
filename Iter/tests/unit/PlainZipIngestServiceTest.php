<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ingest\ExifReaderInterface;
use App\Services\Ingest\PhotoExifParser;
use App\Services\Ingest\ThumbnailGeneratorInterface;
use App\Services\PlainZipIngestService;
use CodeIgniter\Test\CIUnitTestCase;
use ZipArchive;

/**
 * @internal
 */
final class PlainZipIngestServiceTest extends CIUnitTestCase
{
    /** @var list<string> 테스트가 만든 파일(정리 대상) */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function jpegBytes(int $width = 40, int $height = 40): string
    {
        $image = imagecreatetruecolor($width, $height);
        $path = tempnam(sys_get_temp_dir(), 'src_') . '.jpg';
        imagejpeg($image, $path);
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /**
     * @param array<string, string> $entries 파일명 => 내용
     */
    private function makeZip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'plain_') . '.zip';
        $this->tempFiles[] = $path;

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    /**
     * 십진 좌표를 EXIF DMS 배열로 변환한다(초까지 1/100 정밀도).
     *
     * @return array<string, mixed>
     */
    private function exifFor(float $lat, float $lng, ?string $dateTime = '2019:07:18 22:55:29'): array
    {
        $dms = static function (float $value): array {
            $value = abs($value);
            $deg = (int) $value;
            $min = (int) (($value - $deg) * 60);
            $sec = (int) round((($value - $deg) * 60 - $min) * 60 * 100);

            return [$deg . '/1', $min . '/1', $sec . '/100'];
        };

        $exif = [
            'GPSLatitude' => $dms($lat),
            'GPSLatitudeRef' => $lat >= 0 ? 'N' : 'S',
            'GPSLongitude' => $dms($lng),
            'GPSLongitudeRef' => $lng >= 0 ? 'E' : 'W',
        ];
        if ($dateTime !== null) {
            $exif['DateTimeOriginal'] = $dateTime;
        }

        return $exif;
    }

    /**
     * basename => EXIF 배열(null 이면 EXIF 없음) 맵으로 동작하는 가짜 리더.
     *
     * @param array<string, array<string, mixed>|null> $map
     */
    private function fakeReader(array $map): ExifReaderInterface
    {
        return new class ($map) implements ExifReaderInterface {
            /** @param array<string, array<string, mixed>|null> $map */
            public function __construct(private readonly array $map)
            {
            }

            public function read(string $path): ?array
            {
                return $this->map[basename($path)] ?? null;
            }
        };
    }

    public function testExtractsLocationsFromPhotoExif(): void
    {
        $zipPath = $this->makeZip([
            'a.jpg' => $this->jpegBytes(),
            'b.jpg' => $this->jpegBytes(),
        ]);

        $reader = $this->fakeReader([
            'a.jpg' => $this->exifFor(37.5, 127.0, '2019:07:18 09:00:00'),
            'b.jpg' => $this->exifFor(37.6, 127.1, '2019:07:18 12:00:00'),
        ]);

        $service = new PlainZipIngestService(new PhotoExifParser(), $reader);
        $result = $service->ingest($zipPath, 1);

        $this->assertCount(2, $result['locations']);
        $this->assertSame(2, $result['totalCandidates']);
        $this->assertFalse($result['capped']);
        $this->assertSame('a.jpg', $result['locations'][0]->mediaItemId);
        $this->assertEqualsWithDelta(37.5, $result['locations'][0]->lat, 0.001);
    }

    public function testSkipsPhotosWithoutExifGps(): void
    {
        $zipPath = $this->makeZip([
            'a.jpg' => $this->jpegBytes(),
            'b.jpg' => $this->jpegBytes(),
        ]);

        $reader = $this->fakeReader([
            'a.jpg' => $this->exifFor(37.5, 127.0),
            'b.jpg' => null, // EXIF 없음
        ]);

        $service = new PlainZipIngestService(new PhotoExifParser(), $reader);
        $result = $service->ingest($zipPath, 1);

        $this->assertCount(1, $result['locations']);
        $this->assertSame(2, $result['totalCandidates']);
        $this->assertSame('a.jpg', $result['locations'][0]->mediaItemId);
    }

    public function testSkipsPhotosWithoutTakenTime(): void
    {
        $zipPath = $this->makeZip(['a.jpg' => $this->jpegBytes()]);

        $reader = $this->fakeReader([
            'a.jpg' => $this->exifFor(37.5, 127.0, null), // 시각 없음
        ]);

        $service = new PlainZipIngestService(new PhotoExifParser(), $reader);
        $result = $service->ingest($zipPath, 1);

        $this->assertSame([], $result['locations']);
        $this->assertSame(1, $result['totalCandidates']);
    }

    public function testIncludesPhotosWithoutGpsWhenTakenTimePresent(): void
    {
        $zipPath = $this->makeZip([
            'a.jpg' => $this->jpegBytes(),
            'b.jpg' => $this->jpegBytes(),
        ]);

        $reader = $this->fakeReader([
            'a.jpg' => $this->exifFor(37.5, 127.0, '2019:07:18 09:00:00'),
            'b.jpg' => ['DateTimeOriginal' => '2019:07:18 10:00:00'], // GPS 없음, 시각만 있음
        ]);

        $service = new PlainZipIngestService(new PhotoExifParser(), $reader);
        $result = $service->ingest($zipPath, 1);

        $this->assertCount(2, $result['locations']);
        $noGps = array_values(array_filter($result['locations'], static fn ($l) => $l->mediaItemId === 'b.jpg'));
        $this->assertCount(1, $noGps);
        $this->assertNull($noGps[0]->lat);
        $this->assertNull($noGps[0]->lng);
    }

    public function testIgnoresNonPhotoFiles(): void
    {
        $zipPath = $this->makeZip([
            'a.jpg' => $this->jpegBytes(),
            'readme.txt' => 'hello',
            'metadata.json' => '{}',
        ]);

        $reader = $this->fakeReader(['a.jpg' => $this->exifFor(37.5, 127.0)]);

        $service = new PlainZipIngestService(new PhotoExifParser(), $reader);
        $result = $service->ingest($zipPath, 1);

        // 사진 파일만 후보로 센다(txt/json 제외).
        $this->assertSame(1, $result['totalCandidates']);
        $this->assertCount(1, $result['locations']);
    }

    public function testFiltersOutliersByImpossibleSpeed(): void
    {
        $zipPath = $this->makeZip([
            'seoul.jpg' => $this->jpegBytes(),
            'busan.jpg' => $this->jpegBytes(),
        ]);

        $reader = $this->fakeReader([
            'seoul.jpg' => $this->exifFor(37.5665, 126.9780, '2001:09:09 01:46:40'),
            // 서울 → 부산 ~325km 를 5분에 이동 = 비현실적 → 제외
            'busan.jpg' => $this->exifFor(35.1796, 129.0756, '2001:09:09 01:51:40'),
        ]);

        $service = new PlainZipIngestService(new PhotoExifParser(), $reader);
        $result = $service->ingest($zipPath, 1);

        $this->assertCount(1, $result['locations']);
        $this->assertSame('seoul.jpg', $result['locations'][0]->mediaItemId);
    }

    public function testCapsAtMaxItemsButReportsTotalCandidates(): void
    {
        $entries = [];
        $map = [];
        for ($i = 1; $i <= 3; $i++) {
            $entries["photo{$i}.jpg"] = $this->jpegBytes();
            $map["photo{$i}.jpg"] = $this->exifFor(37.5, 127.0, sprintf('2019:07:18 %02d:00:00', $i));
        }
        $zipPath = $this->makeZip($entries);

        $service = new PlainZipIngestService(new PhotoExifParser(), $this->fakeReader($map), null, 200.0, 2);
        $result = $service->ingest($zipPath, 1);

        $this->assertCount(2, $result['locations']);
        $this->assertSame(3, $result['totalCandidates']);
        $this->assertTrue($result['capped']);
    }

    public function testGeneratesThumbnailsOnlyForKeptLocations(): void
    {
        $zipPath = $this->makeZip([
            'seoul.jpg' => $this->jpegBytes(),
            'busan.jpg' => $this->jpegBytes(),
        ]);

        $reader = $this->fakeReader([
            'seoul.jpg' => $this->exifFor(37.5665, 126.9780, '2001:09:09 01:46:40'),
            'busan.jpg' => $this->exifFor(35.1796, 129.0756, '2001:09:09 01:51:40'),
        ]);

        $thumbnailer = $this->createMock(ThumbnailGeneratorInterface::class);
        $thumbnailer->expects($this->once())
            ->method('generate')
            ->with($this->stringContains('seoul.jpg'), 'seoul.jpg', 5)
            ->willReturn('/thumbs/5/seoul.jpg');

        $service = new PlainZipIngestService(new PhotoExifParser(), $reader, $thumbnailer);
        $result = $service->ingest($zipPath, 5);

        $this->assertCount(1, $result['locations']);
        $this->assertSame('/thumbs/5/seoul.jpg', $result['locations'][0]->thumbnailPath);
    }

    public function testExtractedTempDirectoryIsRemovedAfterIngest(): void
    {
        $zipPath = $this->makeZip(['a.jpg' => $this->jpegBytes()]);
        $reader = $this->fakeReader(['a.jpg' => $this->exifFor(37.5, 127.0)]);

        (new PlainZipIngestService(new PhotoExifParser(), $reader))->ingest($zipPath, 1);

        $this->assertSame([], glob(WRITEPATH . 'uploads/photos_*') ?: []);
    }
}
