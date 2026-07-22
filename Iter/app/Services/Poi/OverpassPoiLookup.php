<?php

declare(strict_types=1);

namespace App\Services\Poi;

use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\HTTP\CURLRequest;
use RuntimeException;

/**
 * Overpass API(OpenStreetMap) 기반 주변 업장 조회 구현.
 *
 * API 키 없이 사용 가능한 공개 엔드포인트라 좌표당 결과를 캐싱해 호출을
 * 최소화한다(같은 장소 재조회 방지 + Overpass 사용 정책 준수).
 *
 * @see https://wiki.openstreetmap.org/wiki/Overpass_API
 */
final class OverpassPoiLookup implements PoiLookupInterface
{
    private const ENDPOINT = 'https://overpass-api.de/api/interpreter';

    /** 검색 반경(m) — 사진 GPS 오차와 "같은 골목" 감각을 감안한 값. */
    private const RADIUS_M = 120;

    /** 응답에 담을 최대 업장 수(레이어 UI 가장자리 잡음 방지). */
    private const MAX_RESULTS = 8;

    /** 캐시 보존 기간(초) — 업장 정보는 자주 바뀌지 않으므로 30일. */
    private const CACHE_TTL = 2_592_000;

    /** 조회 대상 amenity 값(업장 성격의 시설만). */
    private const AMENITIES = 'restaurant|cafe|fast_food|bar|pub|ice_cream';

    public function __construct(
        private readonly CURLRequest $client,
        private readonly CacheInterface $cache,
    ) {
    }

    public function findNearby(float $lat, float $lng): array
    {
        // 소수 4자리(약 11m) 반올림 좌표를 캐시 키로 써서 근접 재조회를 흡수한다.
        $cacheKey = sprintf('poi_%.4F_%.4F', $lat, $lng);

        /** @var list<array{name: string, category: string}>|null $cached */
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $places = $this->fetchFromOverpass($lat, $lng);
        $this->cache->save($cacheKey, $places, self::CACHE_TTL);

        return $places;
    }

    /**
     * @return list<array{name: string, category: string}>
     */
    private function fetchFromOverpass(float $lat, float $lng): array
    {
        $query = sprintf(
            '[out:json][timeout:5];node(around:%d,%.7F,%.7F)[name][amenity~"^(%s)$"];out %d;',
            self::RADIUS_M,
            $lat,
            $lng,
            self::AMENITIES,
            self::MAX_RESULTS,
        );

        // 외부 호출은 타임아웃을 반드시 건다(기본 5초). 실패는 예외로 전파해 호출측이 로깅한다.
        // User-Agent 는 필수 — Overpass 는 UA 없는 요청을 406 으로 거부한다(사용 정책).
        $response = $this->client->request('POST', self::ENDPOINT, [
            'form_params' => ['data' => $query],
            'headers' => ['User-Agent' => 'Iter/1.0'],
            'timeout' => 5,
            'http_errors' => false,
        ]);

        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new RuntimeException('Overpass 업장 조회 실패: HTTP ' . $status);
        }

        /** @var array<string, mixed>|null $body */
        $body = json_decode((string) $response->getBody(), true);
        if (! is_array($body)) {
            throw new RuntimeException('Overpass 응답 JSON 파싱 실패');
        }

        $places = [];
        $elements = is_array($body['elements'] ?? null) ? $body['elements'] : [];
        foreach ($elements as $element) {
            $tags = is_array($element['tags'] ?? null) ? $element['tags'] : [];
            $name = (string) ($tags['name'] ?? '');
            if ($name === '') {
                continue; // 이름 없는 시설은 표시 가치가 없다.
            }

            $places[] = [
                'name' => $name,
                'category' => (string) ($tags['amenity'] ?? ''),
            ];

            if (count($places) >= self::MAX_RESULTS) {
                break;
            }
        }

        return $places;
    }
}
