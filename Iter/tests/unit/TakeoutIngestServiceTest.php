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
        $result = $service->ingest($zipPath, 1);

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

    public function testDoesNotGenerateThumbnailsForOutlierFilteredLocations(): void
    {
        // 이상치로 걸러질 사진(busan)에는 썸네일을 만들지 않아야 한다 — 지도에 남지 않는데
        // 썸네일만 디스크에 고아로 남는 것을 방지한다. seoul 만 통과하므로 generate 는 1회.
        $zipPath = $this->makeZip([
            'seoul.jpg' => $this->jpegBytes(),
            'seoul.jpg.json' => $this->geoJson(37.5665, 126.9780, '1000000000'),
            'busan.jpg' => $this->jpegBytes(),
            'busan.jpg.json' => $this->geoJson(35.1796, 129.0756, '1000000300'),
        ]);

        $thumbnailer = $this->createMock(ThumbnailGeneratorInterface::class);
        $thumbnailer->expects($this->once())
            ->method('generate')
            ->with($this->stringContains('seoul.jpg'), 'seoul.jpg', 7)
            ->willReturn('/thumbs/7/seoul.jpg');

        $service = new TakeoutIngestService(new TakeoutMetadataParser(), $thumbnailer);
        $result = $service->ingest($zipPath, 7);

        $this->assertCount(1, $result['locations']);
        $this->assertSame('seoul.jpg', $result['locations'][0]->mediaItemId);
        $this->assertSame('/thumbs/7/seoul.jpg', $result['locations'][0]->thumbnailPath);
    }

    public function testReanchorsAfterFastTravelWhenConsecutivePointsAgree(): void
    {
        // 비행기·KTX 등 200km/h 초과 이동 후 도착지 사진들: 첫 지점은 직전 앵커(서울)
        // 기준으로 이상치처럼 보이지만, 바로 다음 지점과 서로 정합하면(같은 동네)
        // 실제 이동으로 재인정해 둘 다 살려야 한다 — 앵커 고착으로 도착지 동선이
        // 통째로 잘리는 문제 방지.
        $zipPath = $this->makeZip([
            'seoul.jpg' => $this->jpegBytes(),
            'seoul.jpg.json' => $this->geoJson(37.5665, 126.9780, '1000000000'),
            // 1시간 뒤 제주(약 452km → 452km/h): 단독으로는 이상치.
            'jeju1.jpg' => $this->jpegBytes(),
            'jeju1.jpg.json' => $this->geoJson(33.4996, 126.5312, '1000003600'),
            // 5분 뒤 제주 인근: jeju1 과 정합 → 도착지 확정.
            'jeju2.jpg' => $this->jpegBytes(),
            'jeju2.jpg.json' => $this->geoJson(33.5000, 126.5320, '1000003900'),
        ]);

        $service = new TakeoutIngestService(new TakeoutMetadataParser());
        $result = $service->ingest($zipPath, 1);

        $ids = array_map(static fn ($l) => $l->mediaItemId, $result['locations']);
        $this->assertSame(['seoul.jpg', 'jeju1.jpg', 'jeju2.jpg'], $ids);
    }

    public function testSingleGlitchPointIsStillDropped(): void
    {
        // 단발성 GPS 튐(확인해 주는 후속 지점 없음)은 계속 걸러져야 한다.
        $zipPath = $this->makeZip([
            'seoul1.jpg' => $this->jpegBytes(),
            'seoul1.jpg.json' => $this->geoJson(37.5665, 126.9780, '1000000000'),
            // 5분 뒤 부산(325km → 이상치), 이후 다시 서울 — 부산만 튄 것.
            'glitch.jpg' => $this->jpegBytes(),
            'glitch.jpg.json' => $this->geoJson(35.1796, 129.0756, '1000000300'),
            'seoul2.jpg' => $this->jpegBytes(),
            'seoul2.jpg.json' => $this->geoJson(37.5670, 126.9785, '1000000600'),
        ]);

        $service = new TakeoutIngestService(new TakeoutMetadataParser());
        $result = $service->ingest($zipPath, 1);

        $ids = array_map(static fn ($l) => $l->mediaItemId, $result['locations']);
        $this->assertSame(['seoul1.jpg', 'seoul2.jpg'], $ids);
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
