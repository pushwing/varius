<?php

declare(strict_types=1);

namespace App\Services\Ingest;

/**
 * exif_read_data(..., true) 결과 배열에서 GPS 좌표·촬영 시각을 뽑는 순수 파서.
 *
 * I/O 에 의존하지 않으므로 단위 테스트로 완전히 커버한다(DMS→십진수 변환 포함).
 */
final class ExifGpsParser
{
    /**
     * @param array<string, mixed> $exif exif_read_data($path, 'ANY_TAG', true) 형태
     */
    public function parse(array $exif): ?ExifLocation
    {
        $gps = $this->section($exif, 'GPS') ?? $exif;

        $lat = $this->coordinate($gps, 'GPSLatitude', 'GPSLatitudeRef', ['S']);
        $lng = $this->coordinate($gps, 'GPSLongitude', 'GPSLongitudeRef', ['W']);

        if ($lat === null || $lng === null) {
            return null;
        }

        return new ExifLocation($lat, $lng, $this->takenAt($exif));
    }

    /**
     * DMS 배열 + 반구 참조를 십진수 좌표로 변환한다. 남반구·서경은 음수.
     *
     * @param array<string, mixed> $gps
     * @param list<string>         $negativeRefs 음수로 뒤집을 반구 문자('S' 또는 'W')
     */
    private function coordinate(array $gps, string $key, string $refKey, array $negativeRefs): ?float
    {
        $dms = $gps[$key] ?? null;
        if (! is_array($dms) || $dms === []) {
            return null;
        }

        $degrees = $this->rational($dms[0] ?? null);
        $minutes = $this->rational($dms[1] ?? null);
        $seconds = $this->rational($dms[2] ?? null);

        $decimal = $degrees + $minutes / 60 + $seconds / 3600;

        $ref = is_string($gps[$refKey] ?? null) ? strtoupper((string) $gps[$refKey]) : '';
        if (in_array($ref, $negativeRefs, true)) {
            $decimal *= -1;
        }

        return $decimal;
    }

    /**
     * "num/den"·"num"·숫자 형태의 rational 값을 float 으로 변환한다.
     */
    private function rational(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value) || $value === '') {
            return 0.0;
        }

        $parts = explode('/', $value);
        if (count($parts) === 1) {
            return (float) $parts[0];
        }

        $denominator = (float) $parts[1];

        return $denominator === 0.0 ? 0.0 : (float) $parts[0] / $denominator;
    }

    /**
     * DateTimeOriginal("Y:m:d H:i:s") 을 "Y-m-d H:i:s" 로 변환한다. 없으면 null.
     *
     * @param array<string, mixed> $exif
     */
    private function takenAt(array $exif): ?string
    {
        $candidates = [
            $this->section($exif, 'EXIF')['DateTimeOriginal'] ?? null,
            $this->section($exif, 'IFD0')['DateTime'] ?? null,
            $exif['DateTimeOriginal'] ?? null,
        ];

        foreach ($candidates as $raw) {
            if (! is_string($raw) || $raw === '') {
                continue;
            }

            // "2024:03:15 09:30:00" → 날짜 구분자만 '-' 로 치환.
            if (preg_match('/^(\d{4}):(\d{2}):(\d{2}) (.+)$/', $raw, $m) === 1) {
                return sprintf('%s-%s-%s %s', $m[1], $m[2], $m[3], $m[4]);
            }
        }

        return null;
    }

    /**
     * 지정 섹션(GPS·EXIF·IFD0 등)이 배열이면 반환한다.
     *
     * @param array<string, mixed> $exif
     *
     * @return array<string, mixed>|null
     */
    private function section(array $exif, string $name): ?array
    {
        $section = $exif[$name] ?? null;

        return is_array($section) ? $section : null;
    }
}
