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

    /** @return list<array{display_name: string, latitude: float, longitude: float}>|null */
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
            if (! is_array($document) || ! is_numeric($document['y'] ?? null) || ! is_numeric($document['x'] ?? null)) {
                continue;
            }
            $roadAddress = $document['road_address'] ?? null;
            $displayName = is_array($roadAddress) ? ($roadAddress['address_name'] ?? null) : null;
            $displayName = is_string($displayName) && $displayName !== '' ? $displayName : ($document['address_name'] ?? null);
            if (! is_string($displayName) || $displayName === '') {
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
}
