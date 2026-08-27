<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\GeocodingService;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Config\KakaoLocal;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class GeocodingServiceTest extends TestCase
{
    private static function config(): KakaoLocal
    {
        /** @var KakaoLocal */
        return (new ReflectionClass(KakaoLocal::class))->newInstanceWithoutConstructor();
    }

    public function testResultsAreNormalizedToPublicLocationShape(): void
    {
        self::assertSame([
            ['display_name' => '경기 파주시 시청로 50', 'latitude' => 37.7597, 'longitude' => 126.7777],
        ], GeocodingService::normalizeResults([
            ['address_name' => '경기 파주시 시청로 50', 'road_address' => ['address_name' => '경기 파주시 시청로 50'], 'y' => '37.7597', 'x' => '126.7777'],
        ]));
    }

    public function testInvalidResultsAreDiscarded(): void
    {
        self::assertSame([], GeocodingService::normalizeResults([
            ['address_name' => '위도 초과', 'y' => '90.1', 'x' => '126'],
            ['address_name' => '경도 초과', 'y' => '37', 'x' => '-180.1'],
            ['address_name' => '필드 누락', 'y' => '37'],
            ['address_name' => '좌표 아님', 'y' => '서울', 'x' => '126'],
        ]));
    }

    public function testBoundaryCoordinatesAreAccepted(): void
    {
        self::assertCount(1, GeocodingService::normalizeResults([
            ['address_name' => '경계', 'y' => '-90', 'x' => '180'],
        ]));
    }

    public function testSearchUsesKakaoAddressApi(): void
    {
        $config = self::config();
        $config->apiKey = 'test-api-key';
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn(json_encode(['documents' => [['address_name' => '경기 파주시 금촌동', 'y' => '37.7', 'x' => '126.7']]]) ?: '');
        $client = $this->createMock(CURLRequest::class);
        $client->expects(self::once())->method('get')->with($config->addressEndpoint, self::callback(static function (array $options): bool {
            return ($options['headers']['Authorization'] ?? null) === 'KakaoAK test-api-key'
                && ($options['query']['query'] ?? null) === '파주시청';
        }))->willReturn($response);
        self::assertSame([['display_name' => '경기 파주시 금촌동', 'latitude' => 37.7, 'longitude' => 126.7]], (new GeocodingService($client, $config))->search('파주시청'));
    }
}
