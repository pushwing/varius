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
}
