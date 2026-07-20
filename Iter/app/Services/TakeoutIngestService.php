<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Ingest\PhotoLocation;
use App\Services\Ingest\TakeoutMetadataParser;
use App\Services\Ingest\ThumbnailGeneratorInterface;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

/**
 * 업로드된 Google Takeout zip 을 처리해 동선 좌표를 만드는 핵심 서비스.
 *
 * zip 압축 해제 → 사진 ↔ JSON 사이드카 매칭(정확히 일치하는 파일명만) → GPS·촬영시각
 * 파싱 → 200장 상한 → 이상치 필터. 임시 디렉터리는 처리 완료(성공·실패 무관) 즉시 삭제한다.
 */
class TakeoutIngestService
{
    private const DEFAULT_MAX_ITEMS = 200;
    private const EARTH_RADIUS_KM = 6371.0;

    public function __construct(
        private readonly TakeoutMetadataParser $parser,
        private readonly ?ThumbnailGeneratorInterface $thumbnailGenerator = null,
        private readonly float $maxSpeedKmh = 200.0,
        private readonly int $maxItems = self::DEFAULT_MAX_ITEMS,
    ) {
    }

    /**
     * @return array{locations: list<PhotoLocation>, totalCandidates: int}
     */
    public function ingest(string $zipPath): array
    {
        $extractDir = WRITEPATH . 'uploads/takeout_' . uniqid('', true);
        if (! mkdir($extractDir, 0755, true) && ! is_dir($extractDir)) {
            throw new RuntimeException('임시 디렉토리를 만들 수 없습니다: ' . $extractDir);
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException('zip 파일을 열 수 없습니다: ' . $zipPath);
            }
            $zip->extractTo($extractDir);
            $zip->close();

            return $this->processExtracted($extractDir);
        } finally {
            $this->removeDirectory($extractDir);
        }
    }

    /**
     * @return array{locations: list<PhotoLocation>, totalCandidates: int}
     */
    private function processExtracted(string $dir): array
    {
        $jsonFiles = $this->findJsonFiles($dir);
        $totalCandidates = count($jsonFiles);

        $locations = [];
        foreach (array_slice($jsonFiles, 0, $this->maxItems) as $jsonPath) {
            $mediaPath = pathinfo($jsonPath, PATHINFO_DIRNAME) . '/' . pathinfo($jsonPath, PATHINFO_FILENAME);
            if (! is_file($mediaPath)) {
                continue; // 짝이 되는 사진 파일 없음
            }

            $decoded = json_decode((string) file_get_contents($jsonPath), true);
            if (! is_array($decoded)) {
                continue;
            }

            $parsed = $this->parser->parse($decoded);
            if ($parsed === null || $parsed->takenAt === null) {
                continue;
            }

            $sourceItemId = basename($mediaPath);
            $thumbnailPath = $this->thumbnailGenerator?->generate($mediaPath, $sourceItemId);

            $locations[] = new PhotoLocation($sourceItemId, $parsed->lat, $parsed->lng, $parsed->takenAt, $thumbnailPath);
        }

        return [
            'locations' => $this->filterOutliers($locations),
            'totalCandidates' => $totalCandidates,
        ];
    }

    /**
     * @return list<string>
     */
    private function findJsonFiles(string $dir): array
    {
        $found = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && strtolower($fileInfo->getExtension()) === 'json') {
                $found[] = $fileInfo->getPathname();
            }
        }

        sort($found);

        return $found;
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }

    /**
     * 직전 유효 지점 대비 비현실적 이동 속도(기본 200km/h 초과)인 지점을 제외한다.
     *
     * @param list<PhotoLocation> $locations
     *
     * @return list<PhotoLocation>
     */
    private function filterOutliers(array $locations): array
    {
        if (count($locations) <= 1) {
            return $locations;
        }

        usort($locations, static fn (PhotoLocation $a, PhotoLocation $b): int => strcmp($a->takenAt, $b->takenAt));

        $kept = [$locations[0]];
        $previous = $locations[0];

        foreach (array_slice($locations, 1) as $current) {
            if ($this->isReachable($previous, $current)) {
                $kept[] = $current;
                $previous = $current;
            }
        }

        return $kept;
    }

    private function isReachable(PhotoLocation $from, PhotoLocation $to): bool
    {
        $hours = (strtotime($to->takenAt) - strtotime($from->takenAt)) / 3600;
        $distanceKm = $this->haversineKm($from->lat, $from->lng, $to->lat, $to->lng);

        if ($hours <= 0.0) {
            return $distanceKm < 0.001;
        }

        return ($distanceKm / $hours) <= $this->maxSpeedKmh;
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * asin(min(1.0, sqrt($a)));
    }
}
