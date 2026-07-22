<?php

declare(strict_types=1);

namespace App\Services\Ingest;

/**
 * 사진 파일의 EXIF 배열에서 GPS 좌표·촬영 시각을 뽑는 순수 파서.
 *
 * Google Takeout(사이드카 JSON)과 달리, 일반 압축파일 안의 사진은 원본이라
 * GPS 가 사진 자체의 EXIF(GPSLatitude/GPSLongitude + Ref)에 들어있다.
 * exif_read_data() 결과 배열을 입력으로 받아(파일 I/O 는 ExifReaderInterface 담당)
 * 순수 변환만 수행하므로 단위 테스트가 쉽다(TakeoutMetadataParser 와 대칭).
 */
class PhotoExifParser
{
    /**
     * @param array<string, mixed> $exif exif_read_data() 로 읽은 배열
     */
    public function parse(array $exif): ?ExifLocation
    {
        $lat = $this->coordinate($exif, 'GPSLatitude', 'GPSLatitudeRef', ['S']);
        $lng = $this->coordinate($exif, 'GPSLongitude', 'GPSLongitudeRef', ['W']);
        if ($lat === null || $lng === null) {
            return null;
        }

        if ($lat === 0.0 && $lng === 0.0) {
            return null; // (0,0) 은 위치 없음으로 간주한다.
        }

        return new ExifLocation($lat, $lng, $this->takenAt($exif));
    }

    /**
     * GPSLatitude/GPSLongitude(도·분·초 3요소)를 십진수로 변환하고 방위(Ref)로 부호를 정한다.
     *
     * @param array<string, mixed> $exif
     * @param list<string>         $negativeRefs 음수로 뒤집을 방위값(위도 S, 경도 W)
     */
    private function coordinate(array $exif, string $key, string $refKey, array $negativeRefs): ?float
    {
        $parts = $exif[$key] ?? null;
        if (! is_array($parts) || count($parts) < 3) {
            return null;
        }

        $degrees = $this->toFloat($parts[0]);
        $minutes = $this->toFloat($parts[1]);
        $seconds = $this->toFloat($parts[2]);
        if ($degrees === null || $minutes === null || $seconds === null) {
            return null;
        }

        $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);

        $ref = strtoupper((string) ($exif[$refKey] ?? ''));
        if (in_array($ref, $negativeRefs, true)) {
            $decimal = -$decimal;
        }

        return $decimal;
    }

    /**
     * EXIF 유리수 표현("num/den") 또는 숫자를 float 으로 변환한다.
     */
    private function toFloat(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        if (str_contains($value, '/')) {
            [$num, $den] = explode('/', $value, 2);
            if (! is_numeric($num) || ! is_numeric($den) || (float) $den === 0.0) {
                return null;
            }

            return (float) $num / (float) $den;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * 촬영 시각을 "Y-m-d H:i:s" 로 변환한다. DateTimeOriginal 우선, 없으면 DateTime 폴백.
     *
     * @param array<string, mixed> $exif
     */
    private function takenAt(array $exif): ?string
    {
        $raw = $exif['DateTimeOriginal'] ?? $exif['DateTime'] ?? null;
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        // EXIF 표준 형식: "Y:m:d H:i:s".
        $parsed = \DateTimeImmutable::createFromFormat('Y:m:d H:i:s', $raw);
        if ($parsed === false) {
            return null;
        }

        // EXIF 촬영 시각은 카메라 로컬(KST 가정) — 저장 표준인 UTC 로 변환한다.
        return \App\Support\TimeConverter::kstToUtc($parsed->format('Y-m-d H:i:s'));
    }
}
