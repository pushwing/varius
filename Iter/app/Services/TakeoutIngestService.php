<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Ingest\PhotoLocation;
use App\Services\Ingest\TakeoutMetadataParser;
use App\Services\Ingest\ThumbnailGeneratorInterface;
use App\Support\Filesystem;
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

    /** @var list<string> 사이드카 매칭 대상 사진 확장자(동영상 제외) */
    private const PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'heic', 'heif', 'webp', 'gif'];

    /**
     * 사이드카 파일명에서 순서대로 시도할 접미사.
     * 최신 Google Takeout 은 ".supplemental-metadata.json" 을 쓰고,
     * 과거/일부 항목은 여전히 ".json" 만 붙는다.
     *
     * @var list<string>
     */
    private const SIDECAR_SUFFIXES = ['.supplemental-metadata.json', '.json'];

    public function __construct(
        private readonly TakeoutMetadataParser $parser,
        private readonly ?ThumbnailGeneratorInterface $thumbnailGenerator = null,
        private readonly float $maxSpeedKmh = 200.0,
        private readonly int $maxItems = self::DEFAULT_MAX_ITEMS,
    ) {
    }

    /**
     * @return array{locations: list<PhotoLocation>, totalCandidates: int, capped: bool}
     */
    public function ingest(string $zipPath, int $userId): array
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

            return $this->processExtracted($extractDir, $userId);
        } finally {
            Filesystem::removeDirectory($extractDir);
        }
    }

    /**
     * @return array{locations: list<PhotoLocation>, totalCandidates: int, capped: bool}
     */
    private function processExtracted(string $dir, int $userId): array
    {
        $jsonFiles = $this->findJsonFiles($dir);
        $totalCandidates = count($jsonFiles);

        $candidates = [];   // 썸네일 없는 좌표 후보(list<PhotoLocation>)
        $mediaPaths = [];   // source_item_id => 원본 사진 경로(썸네일 생성용)
        foreach (array_slice($jsonFiles, 0, $this->maxItems) as $jsonPath) {
            $mediaPath = $this->mediaPathFor($jsonPath);
            if ($mediaPath === null) {
                continue; // 짝이 되는 사진 파일 없음(또는 사진이 아님)
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
            $candidates[] = new PhotoLocation($sourceItemId, $parsed->lat, $parsed->lng, $parsed->takenAt);
            $mediaPaths[$sourceItemId] = $mediaPath;
        }

        // 이상치를 먼저 걸러, 지도에 실제로 남는 좌표에 대해서만 썸네일을 만든다.
        // (필터링돼 사라질 사진의 썸네일이 디스크에 고아로 남는 것을 방지)
        $kept = $this->filterOutliers($candidates);

        return [
            'locations' => $this->attachThumbnails($kept, $mediaPaths, $userId),
            'totalCandidates' => $totalCandidates,
            'capped' => $totalCandidates > $this->maxItems,
        ];
    }

    /**
     * 이상치 필터를 통과한 좌표에 대해서만 썸네일을 생성해 경로를 채운다.
     *
     * @param list<PhotoLocation>   $locations
     * @param array<string, string> $mediaPaths source_item_id => 원본 사진 경로
     *
     * @return list<PhotoLocation>
     */
    private function attachThumbnails(array $locations, array $mediaPaths, int $userId): array
    {
        if ($this->thumbnailGenerator === null) {
            return $locations;
        }

        $withThumbnails = [];
        foreach ($locations as $location) {
            $mediaPath = $mediaPaths[$location->mediaItemId] ?? null;
            $thumbnailPath = $mediaPath === null
                ? null
                : $this->thumbnailGenerator->generate($mediaPath, $location->mediaItemId, $userId);

            $withThumbnails[] = $thumbnailPath === null
                ? $location
                : new PhotoLocation(
                    $location->mediaItemId,
                    $location->lat,
                    $location->lng,
                    $location->takenAt,
                    $thumbnailPath,
                );
        }

        return $withThumbnails;
    }

    /**
     * 사이드카 JSON 경로에서 짝이 되는 사진 파일 경로를 찾는다.
     * ".supplemental-metadata.json" → ".json" 순으로 접미사를 시도하고,
     * 사진 확장자가 아니거나(예: 동영상) 실제 파일이 없으면 null.
     */
    private function mediaPathFor(string $jsonPath): ?string
    {
        $dir = pathinfo($jsonPath, PATHINFO_DIRNAME);
        $jsonName = pathinfo($jsonPath, PATHINFO_BASENAME);

        foreach (self::SIDECAR_SUFFIXES as $suffix) {
            if (! str_ends_with($jsonName, $suffix)) {
                continue;
            }

            $mediaName = substr($jsonName, 0, -strlen($suffix));
            $extension = strtolower(pathinfo($mediaName, PATHINFO_EXTENSION));
            if (! in_array($extension, self::PHOTO_EXTENSIONS, true)) {
                continue;
            }

            $candidate = $dir . '/' . $mediaName;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
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
        $distanceKm = GeoDistanceCalculator::kilometers($from->lat, $from->lng, $to->lat, $to->lng);

        if ($hours <= 0.0) {
            return $distanceKm < 0.001;
        }

        return ($distanceKm / $hours) <= $this->maxSpeedKmh;
    }
}
