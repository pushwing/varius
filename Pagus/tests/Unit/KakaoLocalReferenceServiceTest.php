<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\KakaoLocalReferenceService;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Config\KakaoLocal;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class KakaoLocalReferenceServiceTest extends TestCase
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

    public function testResultsAreNormalizedToReferenceShape(): void
    {
        self::assertSame([
            ['name' => '파구스식당', 'address' => '경기도 파주시 금릉역로 1', 'phone' => '031-000-0000', 'category' => '음식점 > 한식', 'latitude' => 37.7597, 'longitude' => 126.7777],
        ], KakaoLocalReferenceService::normalizeResults([
            ['place_name' => '파구스식당', 'address_name' => '파주시 금촌동 1', 'road_address_name' => '경기 파주시 금릉역로 1', 'phone' => '031-000-0000', 'category_name' => '음식점 > 한식', 'y' => '37.7597', 'x' => '126.7777'],
        ]));
    }

    public function testFallsBackToAddressNameWhenRoadAddressMissing(): void
    {
        $results = KakaoLocalReferenceService::normalizeResults([
            ['place_name' => '상호', 'address_name' => '지번 주소', 'road_address_name' => '', 'y' => '37.7', 'x' => '126.7'],
        ]);
        self::assertSame('지번 주소', $results[0]['address']);
    }

    public function testMissingPhoneAndCategoryDefaultToEmptyString(): void
    {
        $results = KakaoLocalReferenceService::normalizeResults([
            ['place_name' => '상호', 'address_name' => '주소', 'y' => '37.7', 'x' => '126.7'],
        ]);
        self::assertSame('', $results[0]['phone']);
        self::assertSame('', $results[0]['category']);
    }

    public function testInvalidResultsAreDiscarded(): void
    {
        self::assertSame([], KakaoLocalReferenceService::normalizeResults([
            ['place_name' => '', 'address_name' => '주소', 'y' => '37', 'x' => '126'],
            ['place_name' => '상호만', 'address_name' => '', 'y' => '37', 'x' => '126'],
            ['place_name' => '위도 초과', 'address_name' => '주소', 'y' => '90.1', 'x' => '126'],
            ['place_name' => '경도 초과', 'address_name' => '주소', 'y' => '37', 'x' => '-180.1'],
            ['place_name' => '좌표 아님', 'address_name' => '주소', 'y' => '서울', 'x' => '126'],
            ['place_name' => '필드 누락', 'address_name' => '주소'],
        ]));
    }

    public function testSearchReturnsNullWhenApiKeyNotConfigured(): void
    {
        $config = self::config();
        $config->apiKey = '';
        $service = new KakaoLocalReferenceService(null, $config);

        self::assertNull($service->search('파구스'));
    }

    public function testSearchReturnsNormalizedResultsFromMockedClient(): void
    {
        $config = self::config();
        $config->apiKey = 'test-api-key';

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn(json_encode([
            'documents' => [
                ['place_name' => '파구스식당', 'address_name' => '파주시 금촌동 1', 'road_address_name' => '경기 파주시 금릉역로 1', 'phone' => '031-000-0000', 'category_name' => '음식점 > 한식', 'y' => '37.7597', 'x' => '126.7777'],
            ],
        ]));

        $client = $this->createMock(CURLRequest::class);
        $client->expects(self::once())->method('get')->with(
            $config->endpoint,
            self::callback(static function (array $options) use ($config): bool {
                return ($options['headers']['Authorization'] ?? null) === 'KakaoAK test-api-key'
                    && ($options['query']['query'] ?? null) === '파구스'
                    && $options['timeout'] === $config->timeout;
            }),
        )->willReturn($response);

        $service = new KakaoLocalReferenceService($client, $config);

        self::assertSame([
            ['name' => '파구스식당', 'address' => '경기도 파주시 금릉역로 1', 'phone' => '031-000-0000', 'category' => '음식점 > 한식', 'latitude' => 37.7597, 'longitude' => 126.7777],
        ], $service->search('파구스'));
    }

    public function testSearchReturnsNullWhenClientThrows(): void
    {
        $config = self::config();
        $config->apiKey = 'test-api-key';

        $client = $this->createMock(CURLRequest::class);
        $client->method('get')->willThrowException(new \RuntimeException('timeout'));

        $service = new KakaoLocalReferenceService($client, $config);

        self::assertNull($service->search('파구스'));
    }

    public function testSearchReturnsNullOnNonSuccessStatus(): void
    {
        $config = self::config();
        $config->apiKey = 'test-api-key';

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(401);

        $client = $this->createMock(CURLRequest::class);
        $client->method('get')->willReturn($response);

        $service = new KakaoLocalReferenceService($client, $config);

        self::assertNull($service->search('파구스'));
    }
}
