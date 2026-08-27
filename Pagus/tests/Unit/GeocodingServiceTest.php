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
    /**
     * BaseConfig::__construct()는 CI4 부트스트랩(service() 헬퍼)에 의존하므로,
     * 순수 유닛 테스트에서는 생성자를 거치지 않고 선언된 기본값만 가진 인스턴스를 만든다.
     */
    private static function config(): KakaoLocal
    {
        /** @var KakaoLocal */
        return (new ReflectionClass(KakaoLocal::class))->newInstanceWithoutConstructor();
    }

    public function testResultsPreferRoadAddressForDetailedDisplay(): void
    {
        self::assertSame([
            ['display_name' => '경기 파주시 문산읍 문향로 57', 'latitude' => 37.7597, 'longitude' => 126.7777],
        ], GeocodingService::normalizeResults([
            [
                'address_name' => '경기 파주시 문산읍 선유리 123-4',
                'y' => '37.7597',
                'x' => '126.7777',
                'address' => ['address_name' => '경기 파주시 문산읍 선유리 123-4'],
                'road_address' => ['address_name' => '경기 파주시 문산읍 문향로 57'],
            ],
        ]));
    }

    public function testFallsBackToJibunAddressWhenRoadAddressMissing(): void
    {
        $results = GeocodingService::normalizeResults([
            [
                'address_name' => '경기 파주시 문산읍 선유리 123-4',
                'y' => '37.7597',
                'x' => '126.7777',
                'address' => ['address_name' => '경기 파주시 문산읍 선유리 123-4'],
                'road_address' => null,
            ],
        ]);
        self::assertSame('경기 파주시 문산읍 선유리 123-4', $results[0]['display_name']);
    }

    public function testFallsBackToTopLevelAddressNameWhenNestedAddressesMissing(): void
    {
        $results = GeocodingService::normalizeResults([
            ['address_name' => '경기 파주시청', 'y' => '37.7597', 'x' => '126.7777'],
        ]);
        self::assertSame('경기 파주시청', $results[0]['display_name']);
    }

    public function testInvalidResultsAreDiscarded(): void
    {
        self::assertSame([], GeocodingService::normalizeResults([
            ['address_name' => '위도 초과', 'y' => '90.1', 'x' => '126'],
            ['address_name' => '경도 초과', 'y' => '37', 'x' => '-180.1'],
            ['address_name' => '필드 누락', 'y' => '37'],
            ['address_name' => '좌표 아님', 'y' => '서울', 'x' => '126'],
            ['y' => '37', 'x' => '126'],
        ]));
    }

    public function testBoundaryCoordinatesAreAccepted(): void
    {
        self::assertCount(1, GeocodingService::normalizeResults([
            ['address_name' => '경계', 'y' => '-90', 'x' => '180'],
        ]));
    }

    public function testSearchReturnsNullWhenApiKeyNotConfigured(): void
    {
        $config = self::config();
        $config->apiKey = '';
        $service = new GeocodingService(null, $config);

        self::assertNull($service->search('문산읍 문향로 57'));
    }

    public function testSearchReturnsNormalizedResultsFromMockedClient(): void
    {
        $config = self::config();
        $config->apiKey = 'test-api-key';

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn(json_encode([
            'documents' => [
                [
                    'address_name' => '경기 파주시 문산읍 선유리 123-4',
                    'y' => '37.7597',
                    'x' => '126.7777',
                    'road_address' => ['address_name' => '경기 파주시 문산읍 문향로 57'],
                ],
            ],
        ]));

        $client = $this->createMock(CURLRequest::class);
        $client->expects(self::once())->method('get')->with(
            $config->addressEndpoint,
            self::callback(static function (array $options) use ($config): bool {
                return ($options['headers']['Authorization'] ?? null) === 'KakaoAK test-api-key'
                    && ($options['query']['query'] ?? null) === '문산읍 문향로 57'
                    && $options['timeout'] === $config->timeout;
            }),
        )->willReturn($response);

        $service = new GeocodingService($client, $config);

        self::assertSame([
            ['display_name' => '경기 파주시 문산읍 문향로 57', 'latitude' => 37.7597, 'longitude' => 126.7777],
        ], $service->search('문산읍 문향로 57'));
    }

    public function testSearchReturnsNullWhenClientThrows(): void
    {
        $config = self::config();
        $config->apiKey = 'test-api-key';

        $client = $this->createMock(CURLRequest::class);
        $client->method('get')->willThrowException(new \RuntimeException('timeout'));

        $service = new GeocodingService($client, $config);

        self::assertNull($service->search('문산읍 문향로 57'));
    }

    public function testSearchReturnsNullOnNonSuccessStatus(): void
    {
        $config = self::config();
        $config->apiKey = 'test-api-key';

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(401);

        $client = $this->createMock(CURLRequest::class);
        $client->method('get')->willReturn($response);

        $service = new GeocodingService($client, $config);

        self::assertNull($service->search('문산읍 문향로 57'));
    }
}
