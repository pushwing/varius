<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\HTTP\CURLRequest;
use Config\Geocoding;

final class GeocodingService
{
    public function __construct(private readonly ?CURLRequest $client = null, private readonly ?Geocoding $config = null)
    {
    }

    /** @return list<array{display_name: string, latitude: float, longitude: float}>|null */
    public function search(string $query): ?array
    {
        $config = $this->config ?? config(Geocoding::class);
        try {
            $response = ($this->client ?? service('curlrequest', [
                'timeout' => $config->timeout,
                'connect_timeout' => $config->connectTimeout,
                'http_errors' => false,
            ]))->get($config->endpoint, [
                'headers' => ['Accept' => 'application/json', 'User-Agent' => $config->userAgent],
                'query' => ['q' => $query, 'format' => 'jsonv2', 'addressdetails' => 1, 'countrycodes' => 'kr', 'limit' => $config->resultLimit],
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
        return is_array($decoded) ? self::normalizeResults($decoded) : null;
    }

    /** @param array<mixed> $results @return list<array{display_name: string, latitude: float, longitude: float}> */
    public static function normalizeResults(array $results): array
    {
        $normalized = [];
        foreach ($results as $result) {
            if (! is_array($result) || ! is_string($result['display_name'] ?? null) || ! is_numeric($result['lat'] ?? null) || ! is_numeric($result['lon'] ?? null)) {
                continue;
            }
            $latitude = (float) $result['lat'];
            $longitude = (float) $result['lon'];
            if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
                continue;
            }
            $normalized[] = ['display_name' => $result['display_name'], 'latitude' => $latitude, 'longitude' => $longitude];
        }
        return $normalized;
    }
}
