<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ingest\ExifExtractorInterface;
use App\Services\Ingest\ExifLocation;
use App\Services\Ingest\MediaItemDownloaderInterface;
use App\Services\Ingest\PhotoLocation;
use App\Services\Ingest\ThumbnailGeneratorInterface;
use App\Services\PhotoIngestService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class PhotoIngestServiceTest extends CIUnitTestCase
{
    /**
     * mediaItemId → 로컬 경로 맵을 돌려주는 다운로더 목.
     *
     * @param array<string, string> $paths
     */
    private function downloader(array $paths): MediaItemDownloaderInterface
    {
        $mock = $this->createMock(MediaItemDownloaderInterface::class);
        $mock->method('download')->willReturn($paths);

        return $mock;
    }

    /**
     * 경로 → ExifLocation 맵으로 스텁한 추출기 목.
     *
     * @param array<string, ExifLocation|null> $byPath
     */
    private function extractor(array $byPath): ExifExtractorInterface
    {
        $mock = $this->createMock(ExifExtractorInterface::class);
        $mock->method('extract')->willReturnCallback(
            static fn (string $path): ?ExifLocation => $byPath[$path] ?? null,
        );

        return $mock;
    }

    public function testIngestBuildsLocationsFromExtractedExif(): void
    {
        $mediaItems = [
            ['id' => 'a', 'baseUrl' => 'https://base/a'],
            ['id' => 'b', 'baseUrl' => 'https://base/b'],
        ];

        $service = new PhotoIngestService(
            $this->downloader(['a' => '/fake/a', 'b' => '/fake/b']),
            $this->extractor([
                '/fake/a' => new ExifLocation(37.5, 127.0, '2024-03-15 09:00:00'),
                '/fake/b' => new ExifLocation(37.6, 127.1, '2024-03-15 12:00:00'),
            ]),
        );

        $result = $service->ingest($mediaItems, 'ya29.token');

        $this->assertCount(2, $result);
        $this->assertContainsOnlyInstancesOf(PhotoLocation::class, $result);
        $this->assertSame('a', $result[0]->mediaItemId);
        $this->assertEqualsWithDelta(37.5, $result[0]->lat, 0.0001);
    }

    public function testIngestSkipsPhotosWithoutGps(): void
    {
        $mediaItems = [
            ['id' => 'a', 'baseUrl' => 'https://base/a'],
            ['id' => 'b', 'baseUrl' => 'https://base/b'],
        ];

        $service = new PhotoIngestService(
            $this->downloader(['a' => '/fake/a', 'b' => '/fake/b']),
            $this->extractor([
                '/fake/a' => new ExifLocation(37.5, 127.0, '2024-03-15 09:00:00'),
                '/fake/b' => null, // GPS 없음
            ]),
        );

        $result = $service->ingest($mediaItems, 'ya29.token');

        $this->assertCount(1, $result);
        $this->assertSame('a', $result[0]->mediaItemId);
    }

    public function testIngestFallsBackToCreationTimeWhenExifHasNoDateTime(): void
    {
        $mediaItems = [
            ['id' => 'a', 'baseUrl' => 'https://base/a', 'mediaMetadata' => ['creationTime' => '2024-03-15T09:30:00Z']],
        ];

        $service = new PhotoIngestService(
            $this->downloader(['a' => '/fake/a']),
            $this->extractor(['/fake/a' => new ExifLocation(37.5, 127.0, null)]),
        );

        $result = $service->ingest($mediaItems, 'ya29.token');

        $this->assertCount(1, $result);
        $this->assertSame('2024-03-15 09:30:00', $result[0]->takenAt);
    }

    public function testIngestSkipsWhenNoTimeAvailable(): void
    {
        $mediaItems = [['id' => 'a', 'baseUrl' => 'https://base/a']];

        $service = new PhotoIngestService(
            $this->downloader(['a' => '/fake/a']),
            $this->extractor(['/fake/a' => new ExifLocation(37.5, 127.0, null)]),
        );

        $this->assertSame([], $service->ingest($mediaItems, 'ya29.token'));
    }

    public function testIngestIncludesThumbnailPathWhenGeneratorSucceeds(): void
    {
        $mediaItems = [['id' => 'a', 'baseUrl' => 'https://base/a']];

        $thumbnailer = $this->createMock(ThumbnailGeneratorInterface::class);
        $thumbnailer->expects($this->once())
            ->method('generate')
            ->with('/fake/a', 'a')
            ->willReturn('/thumbs/a.jpg');

        $service = new PhotoIngestService(
            $this->downloader(['a' => '/fake/a']),
            $this->extractor(['/fake/a' => new ExifLocation(37.5, 127.0, '2024-03-15 09:00:00')]),
            200.0,
            $thumbnailer,
        );

        $result = $service->ingest($mediaItems, 'ya29.token');

        $this->assertCount(1, $result);
        $this->assertSame('/thumbs/a.jpg', $result[0]->thumbnailPath);
    }

    public function testIngestThumbnailPathIsNullWhenGeneratorFails(): void
    {
        $mediaItems = [['id' => 'a', 'baseUrl' => 'https://base/a']];

        $thumbnailer = $this->createMock(ThumbnailGeneratorInterface::class);
        $thumbnailer->method('generate')->willReturn(null); // 실패해도 ingest 는 중단되지 않는다.

        $service = new PhotoIngestService(
            $this->downloader(['a' => '/fake/a']),
            $this->extractor(['/fake/a' => new ExifLocation(37.5, 127.0, '2024-03-15 09:00:00')]),
            200.0,
            $thumbnailer,
        );

        $result = $service->ingest($mediaItems, 'ya29.token');

        $this->assertCount(1, $result);
        $this->assertNull($result[0]->thumbnailPath);
    }

    public function testIngestThumbnailPathIsNullWhenNoGeneratorInjected(): void
    {
        $mediaItems = [['id' => 'a', 'baseUrl' => 'https://base/a']];

        $service = new PhotoIngestService(
            $this->downloader(['a' => '/fake/a']),
            $this->extractor(['/fake/a' => new ExifLocation(37.5, 127.0, '2024-03-15 09:00:00')]),
        );

        $result = $service->ingest($mediaItems, 'ya29.token');

        $this->assertNull($result[0]->thumbnailPath);
    }

    public function testFilterOutliersRemovesImpossibleSpeed(): void
    {
        // 서울(10:00) → 부산(10:05): ~325km 를 5분에 이동 = 비현실적 → 제외
        $points = [
            new PhotoLocation('seoul', 37.5665, 126.9780, '2024-01-01 10:00:00'),
            new PhotoLocation('busan', 35.1796, 129.0756, '2024-01-01 10:05:00'),
        ];

        $service = new PhotoIngestService(
            $this->downloader([]),
            $this->extractor([]),
        );

        $result = $service->filterOutliers($points);

        $this->assertCount(1, $result);
        $this->assertSame('seoul', $result[0]->mediaItemId);
    }

    public function testFilterOutliersKeepsRealisticMovement(): void
    {
        // 서울 시내 근거리 이동(10:00 → 10:30) → 유지
        $points = [
            new PhotoLocation('p1', 37.5665, 126.9780, '2024-01-01 10:00:00'),
            new PhotoLocation('p2', 37.5700, 126.9800, '2024-01-01 10:30:00'),
        ];

        $service = new PhotoIngestService(
            $this->downloader([]),
            $this->extractor([]),
        );

        $this->assertCount(2, $service->filterOutliers($points));
    }

    public function testFilterOutliersSortsByTakenAtBeforeChecking(): void
    {
        // 입력 순서가 뒤섞여도 시간순으로 정렬 후 판정한다.
        $points = [
            new PhotoLocation('p2', 37.5700, 126.9800, '2024-01-01 10:30:00'),
            new PhotoLocation('p1', 37.5665, 126.9780, '2024-01-01 10:00:00'),
        ];

        $service = new PhotoIngestService($this->downloader([]), $this->extractor([]));

        $result = $service->filterOutliers($points);

        $this->assertCount(2, $result);
        $this->assertSame('p1', $result[0]->mediaItemId);
        $this->assertSame('p2', $result[1]->mediaItemId);
    }
}
