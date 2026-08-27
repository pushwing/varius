<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RestaurantManagementService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RestaurantManagementServiceTest extends TestCase
{
    public function testInvalidCoordinatesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RestaurantManagementService::assertRestaurantData(['name' => '맛집', 'address' => '파주', 'latitude' => 91, 'longitude' => 126]);
    }

    public function testRequiredRestaurantFieldsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RestaurantManagementService::assertRestaurantData(['name' => '', 'address' => '파주', 'latitude' => 37, 'longitude' => 126]);
    }

    public function testBoundaryCoordinatesAreAccepted(): void
    {
        $this->expectNotToPerformAssertions();
        RestaurantManagementService::assertRestaurantData(['name' => '맛집', 'address' => '파주', 'latitude' => -90, 'longitude' => 180]);
    }

    public function testPublicFiltersAreNormalizedSafely(): void
    {
        self::assertSame([
            'query' => str_repeat('맛', 100),
            'category_id' => null,
            'sort' => 'name',
            'page' => 1,
        ], RestaurantManagementService::normalizePublicFilters([
            'query' => str_repeat('맛', 120),
            'category_id' => 'not-a-number',
            'sort' => 'unsupported',
            'page' => '0',
        ]));
    }

    public function testPublicFilterCategoryAndSortArePreserved(): void
    {
        self::assertSame([
            'query' => '운정',
            'category_id' => 3,
            'sort' => 'newest',
            'page' => 2,
        ], RestaurantManagementService::normalizePublicFilters([
            'query' => ' 운정 ',
            'category_id' => '3',
            'sort' => 'newest',
            'page' => '2',
        ]));
    }
}
