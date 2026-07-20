<?php

declare(strict_types=1);

namespace App\Services\Ingest;

/**
 * exiftool 바이너리(shell_exec) 기반 EXIF 추출기 — 네이티브 exif_read_data 가 못 읽는
 * HEIC 등 예외 포맷의 대체 경로. FallbackExifExtractor 의 2차 추출기로 조합해 쓴다.
 *
 * exiftool -n 모드는 GPS 좌표를 반구 부호가 반영된 십진수로 바로 내려주므로 DMS 변환이 필요 없다.
 * 바이너리 부재·실행 실패·GPS 없음은 모두 null(폴백 없음)로 처리한다.
 */
class ExifToolExtractor implements ExifExtractorInterface
{
    public function extract(string $filePath): ?ExifLocation
    {
        $raw = $this->runExiftool($filePath);
        if ($raw === null) {
            log_message('info', 'exiftool 폴백 미사용(바이너리 없음 또는 실행 실패): file={file}', [
                'file' => basename($filePath),
            ]);

            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! isset($decoded[0]) || ! is_array($decoded[0])) {
            log_message('info', 'exiftool 출력 파싱 실패: file={file}', ['file' => basename($filePath)]);

            return null;
        }

        $data = $decoded[0];
        $lat = $data['GPSLatitude'] ?? null;
        $lng = $data['GPSLongitude'] ?? null;
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            log_message('info', 'exiftool 결과에 GPS 없음: file={file}', ['file' => basename($filePath)]);

            return null;
        }

        return new ExifLocation((float) $lat, (float) $lng, $this->takenAt($data));
    }

    /**
     * exiftool 을 실행해 원시 JSON 출력을 돌려준다. 바이너리가 없거나 실행이 실패하면 null.
     *
     * 파일 경로는 이 다운로더가 만든 임시 파일이지만(사용자 직접 입력 아님) shell 인자로
     * 넘기기 전 반드시 escapeshellarg 로 이스케이프한다.
     */
    protected function runExiftool(string $filePath): ?string
    {
        $command = sprintf(
            'exiftool -n -j -GPSLatitude -GPSLongitude -DateTimeOriginal %s 2>/dev/null',
            escapeshellarg($filePath),
        );

        $output = shell_exec($command);

        return is_string($output) && trim($output) !== '' ? $output : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function takenAt(array $data): ?string
    {
        $raw = $data['DateTimeOriginal'] ?? null;
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        // "2024:03:15 09:30:00" → 날짜 구분자만 '-' 로 치환.
        if (preg_match('/^(\d{4}):(\d{2}):(\d{2}) (.+)$/', $raw, $m) === 1) {
            return sprintf('%s-%s-%s %s', $m[1], $m[2], $m[3], $m[4]);
        }

        return null;
    }
}
