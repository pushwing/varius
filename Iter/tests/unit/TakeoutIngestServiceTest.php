<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ingest\TakeoutMetadataParser;
use App\Services\Ingest\ThumbnailGeneratorInterface;
use App\Services\TakeoutIngestService;
use CodeIgniter\Test\CIUnitTestCase;
use ZipArchive;

/**
 * @internal
 */
final class TakeoutIngestServiceTest extends CIUnitTestCase
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

    /**
     * width x height JPEG 바이트를 만든다(GD).
     */
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
     * entries: 파일명 => 내용(문자열) 맵으로 zip 을 만든다.
     *
     * @param array<string, string> $entries
     */
    private function makeZip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'takeout_') . '.zip';
        $this->tempFiles[] = $path;

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    private function geoJson(float $lat, float $lng, string $timestamp = '1563490529'): string
    {
        return (string) json_encode([
            'geoData' => ['latitude' => $lat, 'longitude' => $lng],
            'photoTakenTime' => ['timestamp' => $timestamp],
        ]);
    }

    public function testExtractsLocationsFromMatchedPhotoJsonPairs(): void
    {
        $zipPath = $this->makeZip([
            'photo1.jpg' => $this->jpegBytes(),
            'photo1.jpg.json' => $this->geoJson(37.5665, 126.9780, '1563490529'),
        ]);

        $service = new TakeoutIngestService(new TakeoutMetadataParser());
        $result = $service->ingest($zipPath, 1);

        $this->assertCount(1, $result['locations']);
        $this->assertSame(1, $result['totalCandidates']);
        $this->assertFalse($result['capped']);
        $this->assertSame('photo1.jpg', $result['locations'][0]->mediaItemId);
        $this->assertEqualsWithDelta(37.5665, $result['locations'][0]->lat, 0.0001);
        $this->assertSame('2019-07-18 22:55:29', $result['locations'][0]->takenAt);
    }

    public function testExtractsLocationsFromSupplementalMetadataJsonSuffix(): void
    {
        // 최신 Google Takeout 은 "<파일명>.json" 대신
        // "<파일명>.supplemental-metadata.json" 로 사이드카를 내보낸다.
        $zipPath = $this->makeZip([
            'IMG_3475.JPG' => $this->jpegBytes(),
            'IMG_3475.JPG.supplemental-metadata.json' => $this->geoJson(37.5665, 126.9780, '1563490529'),
        ]);

        $service = new TakeoutIngestService(new TakeoutMetadataParser());
        $result = $service->ingest($zipPath, 1);

        $this->assertCount(1, $result['locations']);
        $this->assertSame(1, $result['totalCandidates']);
        $this->assertSame('IMG_3475.JPG', $result['locations'][0]->mediaItemId);
    }

    public function testSkipsVideoSidecarsEvenWhenMatched(): void
    {
        $zipPath = $this->makeZip([
            'IMG_3477.MOV' => 'not-a-real-video',
            'IMG_3477.MOV.supplemental-metadata.json' => $this->geoJson(37.5665, 126.9780, '1563490529'),
        ]);

        $service = new TakeoutIngestService(new TakeoutMetadataParser());
        $result = $service->ingest($zipPath, 1);

        $this->assertSame([], $result['locations']);
        $this->assertSame(1, $result['totalCandidates']);
    }

    public function testSkipsJsonWithoutMatchingPhoto(): void
    {
        $zipPath = $this->makeZip([
            'orphan.jpg.json' => $this->geoJson(37.5665, 126.9780),
        ]);

        $service = new TakeoutIngestService(new TakeoutMetadataParser());
        $result = $service->ingest($zipPath, 1);

        $this->assertSame([], $result['locations']);
        $this->assertSame(1, $result['totalCandidates']);
    }

    public function testSkipsPhotosWithoutGpsInJson(): void
    {
        $zipPath = $this->makeZip([
            'photo1.jpg' => $this->jpegBytes(),
            'photo1.jpg.json' => (string) json_encode(['photoTakenTime' => ['timestamp' => '1563490529']]),
        ]);

        $service = new TakeoutIngestService(new TakeoutMetadataParser());
        $result = $service->ingest($zipPath, 1);

        $this->assertSame([], $result['locations']);
    }

    public function testCapsAtMaxItemsButReportsTotalCandidates(): void
    {
        // 이상치 필터와 얽히지 않도록 동일 좌표·충분히 벌어진 시간으로 고정한다.
        $entries = [];
        for ($i = 1; $i <= 3; $i++) {
            $entries["photo{$i}.jpg"] = $this->jpegBytes();
            $entries["photo{$i}.jpg.json"] = $this->geoJson(37.5665, 126.9780, (string) (1563490529 + $i * 3600));
        }
        $zipPath = $this->makeZip($entries);

        $service = new TakeoutIngestService(new TakeoutMetadataParser(), null, 200.0, maxItems: 2);
        $result = $service->ingest($zipPath, 1);

        $this->assertCount(2, $result['locations']);
        $this->assertSame(3, $result['totalCandidates']);
        $this->assertTrue($result['capped']);
    }

    public function testNotCappedWhenSomeCandidatesLackGpsButUnderMaxItems(): void
    {
        // 상한(200장)보다 훨씬 적은 3개 중 1개만 GPS 가 없어 제외되는 경우 —
        // "상한까지만 처리됨"이 아니라 단순히 위치를 못 찾은 것으로 구분돼야 한다.
        $zipPath = $this->makeZip([
            'photo1.jpg' => $this->jpegBytes(),
            'photo1.jpg.json' => $this->geoJson(37.5665, 126.9780, '1563490529'),
            'photo2.jpg' => $this->jpegBytes(),
            'photo2.jpg.json' => (string) json_encode(['photoTakenTime' => ['timestamp' => '1563490529']]),
        ]);

        $service = new TakeoutIngestService(new TakeoutMetadataParser());
        $result = $service->ingest($zipPath);

        $this->assertCount(1, $result['locations']);
        $this->assertSame(2, $result['totalCandidates']);
        $this->assertFalse($result['capped']);
    }

    public function testFiltersOutliersByImpossibleSpeed(): void
    {
        $zipPath = $this->makeZip([
            'seoul.jpg' => $this->jpegBytes(),
            'seoul.jpg.json' => $this->geoJson(37.5665, 126.9780, '1000000000'),
            'busan.jpg' => $this->jpegBytes(),
            // 서울 → 부산 ~325km 를 5분(300초)에 이동 = 비현실적 → 제외
            'busan.jpg.json' => $this->geoJson(35.1796, 129.0756, '1000000300'),
        ]);

        $service = new TakeoutIngestService(new TakeoutMetadataParser());
        $result = $service->ingest($zipPath, 1);

        $this->assertCount(1, $result['locations']);
        $this->assertSame('seoul.jpg', $result['locations'][0]->mediaItemId);
    }

    public function testGeneratesThumbnailWhenGeneratorProvided(): void
    {
        $zipPath = $this->makeZip([
            'photo1.jpg' => $this->jpegBytes(),
            'photo1.jpg.json' => $this->geoJson(37.5665, 126.9780),
        ]);

        $thumbnailer = $this->createMock(ThumbnailGeneratorInterface::class);
        $thumbnailer->expects($this->once())
            ->method('generate')
            ->with($this->stringContains('photo1.jpg'), 'photo1.jpg', 42)
            ->willReturn('/thumbs/42/photo1.jpg');

        $service = new TakeoutIngestService(new TakeoutMetadataParser(), $thumbnailer);
        $result = $service->ingest($zipPath, 42);

        $this->assertSame('/thumbs/42/photo1.jpg', $result['locations'][0]->thumbnailPath);
    }

    public function testPassesUserIdToThumbnailGeneratorSoDifferentUsersDoNotShareAPath(): void
    {
        // 흔한 카메라 기본 파일명(source_item_id) 충돌 시에도 사용자별로 다른 경로에
        // 저장되도록, ingest() 는 호출자가 넘긴 userId 를 썸네일 생성기에 그대로 전달해야 한다.
        $zipPathUserA = $this->makeZip([
            'IMG_0001.JPG' => $this->jpegBytes(),
            'IMG_0001.JPG.json' => $this->geoJson(37.5665, 126.9780),
        ]);
        $zipPathUserB = $this->makeZip([
            'IMG_0001.JPG' => $this->jpegBytes(),
            'IMG_0001.JPG.json' => $this->geoJson(35.1796, 129.0756),
        ]);

        $thumbnailer = $this->createMock(ThumbnailGeneratorInterface::class);
        $thumbnailer->expects($this->exactly(2))
            ->method('generate')
            ->willReturnCallback(fn (string $sourcePath, string $mediaItemId, int $userId): string => "/thumbs/{$userId}/{$mediaItemId}");

        $service = new TakeoutIngestService(new TakeoutMetadataParser(), $thumbnailer);
        $resultA = $service->ingest($zipPathUserA, 1);
        $resultB = $service->ingest($zipPathUserB, 2);

        $this->assertSame('/thumbs/1/IMG_0001.JPG', $resultA['locations'][0]->thumbnailPath);
        $this->assertSame('/thumbs/2/IMG_0001.JPG', $resultB['locations'][0]->thumbnailPath);
    }

    public function testExtractedTempDirectoryIsRemovedAfterIngest(): void
    {
        $zipPath = $this->makeZip([
            'photo1.jpg' => $this->jpegBytes(),
            'photo1.jpg.json' => $this->geoJson(37.5665, 126.9780),
        ]);

        $service = new TakeoutIngestService(new TakeoutMetadataParser());
        $service->ingest($zipPath, 1);

        $leftover = glob(WRITEPATH . 'uploads/takeout_*');
        $this->assertSame([], $leftover, '압축 해제 임시 디렉터리가 정리되지 않았습니다.');
    }
}
