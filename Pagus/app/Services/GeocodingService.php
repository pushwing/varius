<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\HTTP\CURLRequest;
use Config\KakaoLocal;

final class GeocodingService
{
    public function __construct(private readonly ?CURLRequest $client = null, private readonly ?KakaoLocal $config = null)
    {
    }

    /**
     * 카카오 주소 검색으로 한국식 상세 주소(도로명/지번)와 좌표를 조회한다.
     * 카카오 로컬 API는 국내 주소만 다루므로 검색 범위가 자연히 내국으로 한정된다.
     *
     * @return list<array{display_name: string, latitude: float, longitude: float}>|null
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
            ]))->get($config->addressEndpoint, [
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

    /** @param array<mixed> $documents @return list<array{display_name: string, latitude: float, longitude: float}> */
    public static function normalizeResults(array $documents): array
    {
        $normalized = [];
        foreach ($documents as $document) {
            if (! is_array($document)) {
                continue;
            }
            $displayName = self::formatAddress(self::detailedAddress($document));
            if ($displayName === null || ! is_numeric($document['y'] ?? null) || ! is_numeric($document['x'] ?? null)) {
                continue;
            }
            $latitude = (float) $document['y'];
            $longitude = (float) $document['x'];
            if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
                continue;
            }
            $normalized[] = ['display_name' => $displayName, 'latitude' => $latitude, 'longitude' => $longitude];
        }
        return $normalized;
    }

    /** @param array<mixed> $document */
    private static function detailedAddress(array $document): ?string
    {
        $roadAddress = $document['road_address'] ?? null;
        if (is_array($roadAddress) && is_string($roadAddress['address_name'] ?? null) && $roadAddress['address_name'] !== '') {
            return $roadAddress['address_name'];
        }
        $address = $document['address'] ?? null;
        if (is_array($address) && is_string($address['address_name'] ?? null) && $address['address_name'] !== '') {
            return $address['address_name'];
        }
        return is_string($document['address_name'] ?? null) && $document['address_name'] !== '' ? $document['address_name'] : null;
    }

    private static function formatAddress(?string $address): ?string
    {
        if ($address === null) {
            return null;
        }

        return preg_replace('/^경기(?=\s)/u', '경기도', $address) ?? $address;
    }
}
