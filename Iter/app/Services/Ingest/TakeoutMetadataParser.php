<?php

declare(strict_types=1);

namespace App\Services\Ingest;

/**
 * Google Takeout 사진 JSON 사이드카에서 GPS 좌표·촬영 시각을 뽑는 순수 파서.
 *
 * Takeout 은 위치 없음을 (0.0, 0.0) 으로 표현하며, geoData(보정값)와
 * geoDataExif(원본 EXIF 값) 두 필드를 모두 제공한다 — geoData 를 우선하고
 * 둘 다 0.0 이면 geoDataExif 로 폴백한다.
 */
class TakeoutMetadataParser
{
    /**
     * @param array<string, mixed> $json Takeout 사진 JSON 사이드카를 json_decode 한 배열
     */
    public function parse(array $json): ?ExifLocation
    {
        $coords = $this->coordinates($json, 'geoData') ?? $this->coordinates($json, 'geoDataExif');
        if ($coords === null) {
            return null;
        }

        return new ExifLocation($coords[0], $coords[1], $this->takenAt($json));
    }

    /**
     * @param array<string, mixed> $json
     *
     * @return array{0: float, 1: float}|null
     */
    private function coordinates(array $json, string $key): ?array
    {
        $section = $json[$key] ?? null;
        if (! is_array($section)) {
            return null;
        }

        $lat = $section['latitude'] ?? null;
        $lng = $section['longitude'] ?? null;
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return null;
        }

        $lat = (float) $lat;
        $lng = (float) $lng;

        if ($lat === 0.0 && $lng === 0.0) {
            return null; // Takeout 은 위치 없음을 (0.0, 0.0) 으로 표현한다.
        }

        return [$lat, $lng];
    }

    /**
     * photoTakenTime.timestamp(Unix epoch 초, 문자열) 를 "Y-m-d H:i:s" 로 변환한다. 없으면 null.
     *
     * @param array<string, mixed> $json
     */
    private function takenAt(array $json): ?string
    {
        $photoTakenTime = $json['photoTakenTime'] ?? null;
        $timestamp = is_array($photoTakenTime) ? ($photoTakenTime['timestamp'] ?? null) : null;

        if (! is_numeric($timestamp)) {
            return null;
        }

        return date('Y-m-d H:i:s', (int) $timestamp);
    }
}
