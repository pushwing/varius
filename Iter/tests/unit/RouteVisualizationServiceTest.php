<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PhotoLocationModel;
use App\Services\RouteVisualizationService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class RouteVisualizationServiceTest extends CIUnitTestCase
{
    /**
     * findByUserOrdered 가 주어진 행들을 돌려주도록 스텁한 서비스.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function serviceWithRows(array $rows): RouteVisualizationService
    {
        $model = $this->createMock(PhotoLocationModel::class);
        $model->method('findByUserOrdered')->willReturn($rows);

        return new RouteVisualizationService($model);
    }

    public function testDisplaysTimesInKstAndGroupsAcrossUtcDateBoundary(): void
    {
        // 저장은 UTC — UTC 저녁 사진은 KST 다음 날로 그룹핑·표시돼야 한다.
        $service = $this->serviceWithRows([
            ['id' => 1, 'source_item_id' => 'm1', 'lat' => '37.5000000', 'lng' => '127.0000000', 'taken_at' => '2024-03-15 23:30:00'],
        ]);

        $result = $service->buildForUser(1);

        $this->assertSame('2024-03-16', $result['dates'][0]['date']);
        $this->assertSame('2024-03-16 08:30:00', $result['dates'][0]['points'][0]['taken_at']);
    }

    public function testGroupsPointsByDateWithDistinctColors(): void
    {
        $service = $this->serviceWithRows([
            ['source_item_id' => 'm1', 'lat' => '37.5000000', 'lng' => '127.0000000', 'taken_at' => '2024-03-15 09:00:00'],
            ['source_item_id' => 'm2', 'lat' => '37.6000000', 'lng' => '127.1000000', 'taken_at' => '2024-03-15 12:00:00'],
            ['source_item_id' => 'm3', 'lat' => '35.1000000', 'lng' => '129.0000000', 'taken_at' => '2024-03-16 08:00:00'],
        ]);

        $result = $service->buildForUser(1);

        $this->assertCount(2, $result['dates']);
        $this->assertSame('2024-03-15', $result['dates'][0]['date']);
        $this->assertSame('2024-03-16', $result['dates'][1]['date']);
        $this->assertCount(2, $result['dates'][0]['points']);
        $this->assertCount(1, $result['dates'][1]['points']);

        // 날짜마다 색상이 달라야 한다.
        $this->assertNotSame($result['dates'][0]['color'], $result['dates'][1]['color']);
    }

    public function testPointShapeHasFloatCoordsAndMediaItemId(): void
    {
        $service = $this->serviceWithRows([
            ['id' => 1, 'source_item_id' => 'm1', 'lat' => '37.5000000', 'lng' => '127.0000000', 'taken_at' => '2024-03-15 09:00:00'],
        ]);

        $point = $service->buildForUser(1)['dates'][0]['points'][0];

        $this->assertSame('m1', $point['media_item_id']);
        $this->assertIsFloat($point['lat']);
        $this->assertIsFloat($point['lng']);
        $this->assertEqualsWithDelta(37.5, $point['lat'], 0.0001);
        // 저장(UTC) 09:00 → 표시(KST) 18:00.
        $this->assertSame('2024-03-15 18:00:00', $point['taken_at']);
    }

    public function testPointHasThumbnailUrlWhenThumbnailPathPresent(): void
    {
        $service = $this->serviceWithRows([
            ['id' => 42, 'source_item_id' => 'm1', 'lat' => '37.5', 'lng' => '127.0', 'taken_at' => '2024-03-15 09:00:00', 'thumbnail_path' => '/writable/uploads/thumbnails/m1.jpg'],
        ]);

        $point = $service->buildForUser(1)['dates'][0]['points'][0];

        $this->assertSame('/thumbnails/42', $point['thumbnail_url']);
    }

    public function testPointHasNullThumbnailUrlWhenNoThumbnailPath(): void
    {
        $service = $this->serviceWithRows([
            ['id' => 1, 'source_item_id' => 'm1', 'lat' => '37.5', 'lng' => '127.0', 'taken_at' => '2024-03-15 09:00:00', 'thumbnail_path' => null],
        ]);

        $point = $service->buildForUser(1)['dates'][0]['points'][0];

        $this->assertNull($point['thumbnail_url']);
    }

    public function testPointsWithinDateKeepChronologicalOrder(): void
    {
        $service = $this->serviceWithRows([
            ['source_item_id' => 'early', 'lat' => '37.5', 'lng' => '127.0', 'taken_at' => '2024-03-15 09:00:00'],
            ['source_item_id' => 'late', 'lat' => '37.6', 'lng' => '127.1', 'taken_at' => '2024-03-15 12:00:00'],
        ]);

        $points = $service->buildForUser(1)['dates'][0]['points'];

        $this->assertSame('early', $points[0]['media_item_id']);
        $this->assertSame('late', $points[1]['media_item_id']);
    }

    public function testReturnsEmptyDatesWhenNoLocations(): void
    {
        $service = $this->serviceWithRows([]);

        $this->assertSame(['dates' => []], $service->buildForUser(1));
    }

    public function testExcludesPhotosWithoutCoordinatesFromMap(): void
    {
        $service = $this->serviceWithRows([
            ['id' => 1, 'source_item_id' => 'm1', 'lat' => '37.5000000', 'lng' => '127.0000000', 'taken_at' => '2024-03-15 09:00:00'],
            ['id' => 2, 'source_item_id' => 'm2', 'lat' => null, 'lng' => null, 'taken_at' => '2024-03-15 09:30:00'],
        ]);

        $result = $service->buildForUser(1);

        $this->assertCount(1, $result['dates'][0]['points']);
        $this->assertSame('m1', $result['dates'][0]['points'][0]['media_item_id']);
    }

    public function testClustersNearbyPointsIntoOneGroup(): void
    {
        // 위도 0.0002도 차이 ≈ 22m — 같은 장소 연속촬영으로 묶여야 한다.
        $service = $this->serviceWithRows([
            ['id' => 1, 'source_item_id' => 'm1', 'lat' => '37.50000', 'lng' => '127.00000', 'taken_at' => '2024-03-15 09:00:00'],
            ['id' => 2, 'source_item_id' => 'm2', 'lat' => '37.50020', 'lng' => '127.00000', 'taken_at' => '2024-03-15 09:00:05'],
        ]);

        $clusters = $service->buildForUser(1)['dates'][0]['clusters'];

        $this->assertCount(1, $clusters);
        $this->assertCount(2, $clusters[0]['photos']);
        $this->assertSame('m1', $clusters[0]['photos'][0]['media_item_id']);
        $this->assertSame('m2', $clusters[0]['photos'][1]['media_item_id']);
    }

    public function testKeepsFarApartPointsAsSeparateClusters(): void
    {
        // 위도 0.01도 차이 ≈ 1.1km — 같은 장소로 보기엔 너무 멀다.
        $service = $this->serviceWithRows([
            ['id' => 1, 'source_item_id' => 'm1', 'lat' => '37.5000', 'lng' => '127.0000', 'taken_at' => '2024-03-15 09:00:00'],
            ['id' => 2, 'source_item_id' => 'm2', 'lat' => '37.5100', 'lng' => '127.0000', 'taken_at' => '2024-03-15 09:05:00'],
        ]);

        $clusters = $service->buildForUser(1)['dates'][0]['clusters'];

        $this->assertCount(2, $clusters);
        $this->assertCount(1, $clusters[0]['photos']);
        $this->assertCount(1, $clusters[1]['photos']);
    }

    public function testClusterPhotoCarriesTakenAtAndThumbnailUrl(): void
    {
        $service = $this->serviceWithRows([
            ['id' => 42, 'source_item_id' => 'm1', 'lat' => '37.5', 'lng' => '127.0', 'taken_at' => '2024-03-15 09:00:00', 'thumbnail_path' => '/thumbs/m1.jpg'],
        ]);

        $photo = $service->buildForUser(1)['dates'][0]['clusters'][0]['photos'][0];

        // 저장(UTC) 09:00 → 표시(KST) 18:00.
        $this->assertSame('2024-03-15 18:00:00', $photo['taken_at']);
        $this->assertSame('/thumbnails/42', $photo['thumbnail_url']);
    }

    public function testClusterCoordinatesAreFloats(): void
    {
        $service = $this->serviceWithRows([
            ['id' => 1, 'source_item_id' => 'm1', 'lat' => '37.5000000', 'lng' => '127.0000000', 'taken_at' => '2024-03-15 09:00:00'],
        ]);

        $cluster = $service->buildForUser(1)['dates'][0]['clusters'][0];

        $this->assertIsFloat($cluster['lat']);
        $this->assertIsFloat($cluster['lng']);
        $this->assertEqualsWithDelta(37.5, $cluster['lat'], 0.0001);
    }
}
