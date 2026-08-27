<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\HTTP\CURLRequest;
use Config\KakaoLocal;

final class KakaoLocalReferenceService
{
    public function __construct(private readonly ?CURLRequest $client = null, private readonly ?KakaoLocal $config = null)
    {
    }

    /**
     * 카카오 로컬 장소 검색으로 맛집 등록 참고 데이터를 조회한다.
     * API 키 미설정·호출 실패·타임아웃 시 null을 반환해 운영자 직접 입력을 막지 않는다.
     *
     * @return list<array{name: string, address: string, phone: string, category: string, latitude: float, longitude: float}>|null
     */
    public function search(string $query): ?array
    {
        $config = $this->config ?? config(KakaoLocal::class);
        if ($config->apiKey === '') {
            return null;
        }

        try {
            $response = ($this->client ?? service('curlrequest', [
                'timeout' => $config->timeout,
                'connect_timeout' => $config->connectTimeout,
                'http_errors' => false,
            ]))->get($config->endpoint, [
                'headers' => ['Authorization' => 'KakaoAK ' . $config->apiKey, 'Accept' => 'application/json'],
                'query' => ['query' => $query, 'size' => $config->resultLimit],
                'timeout' => $config->timeout,
                'connect_timeout' => $config->connectTimeout,
            ]);
        } catch (\Throwable) {
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            return null;
        }
        $decoded = json_decode($response->getBody(), true);
        $documents = is_array($decoded) ? ($decoded['documents'] ?? null) : null;
        return is_array($documents) ? self::normalizeResults($documents) : null;
    }

    /**
     * @param array<mixed> $documents
     * @return list<array{name: string, address: string, phone: string, category: string, latitude: float, longitude: float}>
     */
    public static function normalizeResults(array $documents): array
    {
        $normalized = [];
        foreach ($documents as $document) {
            if (! is_array($document)) {
                continue;
            }
            $name = $document['place_name'] ?? null;
            $roadAddress = $document['road_address_name'] ?? '';
            $address = is_string($roadAddress) && $roadAddress !== '' ? $roadAddress : ($document['address_name'] ?? null);
            if (! is_string($name) || $name === '' || ! is_string($address) || $address === '' || ! is_numeric($document['y'] ?? null) || ! is_numeric($document['x'] ?? null)) {
                continue;
            }
            $latitude = (float) $document['y'];
            $longitude = (float) $document['x'];
            if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
                continue;
            }
            $normalized[] = [
                'name' => $name,
                'address' => preg_replace('/^경기(?=\s)/u', '경기도', $address) ?? $address,
                'phone' => is_string($document['phone'] ?? null) ? $document['phone'] : '',
                'category' => is_string($document['category_name'] ?? null) ? $document['category_name'] : '',
                'latitude' => $latitude,
                'longitude' => $longitude,
            ];
        }
        return $normalized;
    }
}
