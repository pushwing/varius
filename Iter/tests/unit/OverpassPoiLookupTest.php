<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Poi\OverpassPoiLookup;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockCache;
use RuntimeException;

/**
 * @internal
 */
final class OverpassPoiLookupTest extends CIUnitTestCase
{
    /**
     * Overpass 응답(JSON)을 돌려주는 스텁 클라이언트로 조립한 서비스와 호출 기록.
     *
     * @param array<string, mixed> $overpassBody
     *
     * @return array{OverpassPoiLookup, HttpCallRecorder}
     */
    private function lookupReturning(array $overpassBody, int $status = 200): array
    {
        $response = $this->createMock(Response::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn(json_encode($overpassBody) ?: '');

        $recorder = new HttpCallRecorder();
        $client = $this->createMock(CURLRequest::class);
        $client->method('request')->willReturnCallback(
            static function (string $method, string $url, array $options = []) use ($response, $recorder) {
                $recorder->calls++;
                $recorder->options = $options;

                return $response;
            },
        );

        return [new OverpassPoiLookup($client, new MockCache()), $recorder];
    }

    public function testReturnsNamedPlacesWithCategories(): void
    {
        [$lookup] = $this->lookupReturning([
            'elements' => [
                ['tags' => ['name' => '소문난 삼계탕', 'amenity' => 'restaurant']],
                ['tags' => ['name' => '북촌 커피', 'amenity' => 'cafe']],
                ['tags' => ['amenity' => 'cafe']], // 이름 없는 요소는 제외
            ],
        ]);

        $places = $lookup->findNearby(37.5796, 126.9770);

        $this->assertSame([
            ['name' => '소문난 삼계탕', 'category' => 'restaurant'],
            ['name' => '북촌 커피', 'category' => 'cafe'],
        ], $places);
    }

    public function testRequestUsesFiveSecondTimeoutAndUserAgent(): void
    {
        [$lookup, $recorder] = $this->lookupReturning(['elements' => []]);

        $lookup->findNearby(37.5, 127.0);

        $this->assertNotNull($recorder->options);
        $this->assertSame(5, $recorder->options['timeout']);
        // Overpass 는 User-Agent 없는 요청을 406 으로 거부한다(사용 정책).
        $this->assertSame('Iter/1.0', $recorder->options['headers']['User-Agent']);
    }

    public function testCachesResultByRoundedCoordinate(): void
    {
        [$lookup, $recorder] = $this->lookupReturning([
            'elements' => [['tags' => ['name' => '카페', 'amenity' => 'cafe']]],
        ]);

        // 반올림(소수 4자리, 약 11m) 후 같은 좌표는 외부 호출 없이 캐시로 응답한다.
        $first = $lookup->findNearby(37.50001, 127.00001);
        $second = $lookup->findNearby(37.50004, 127.00004);

        $this->assertSame($first, $second);
        $this->assertSame(1, $recorder->calls);
    }

    public function testThrowsOnHttpError(): void
    {
        [$lookup] = $this->lookupReturning([], 504);

        $this->expectException(RuntimeException::class);
        $lookup->findNearby(37.5, 127.0);
    }
}

/**
 * 스텁 클라이언트가 받은 호출 횟수·옵션 기록(참조 파라미터 대체).
 */
final class HttpCallRecorder
{
    public int $calls = 0;

    /** @var array<string, mixed>|null */
    public ?array $options = null;
}
