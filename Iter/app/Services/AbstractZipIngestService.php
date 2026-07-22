<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Ingest\PhotoLocation;
use App\Services\Ingest\ThumbnailGeneratorInterface;
use App\Support\Filesystem;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

/**
 * 업로드된 zip 을 처리해 동선 좌표(PhotoLocation[])를 만드는 인제스트 공통 골격.
 *
 * 압축 해제 → 좌표 후보 추출(서브클래스가 구현) → 200장 상한 → 이상치 필터 →
 * 살아남은 좌표에만 썸네일 부착 → 임시 디렉터리 정리(성공·실패 무관)까지의
 * 흐름을 담는다. "좌표를 어디서 얻느냐"만 서브클래스마다 다르다:
 * - {@see TakeoutIngestService}: JSON 사이드카(Takeout)
 * - {@see PlainZipIngestService}: 사진 EXIF(일반 압축파일)
 */
abstract class AbstractZipIngestService
{
    protected const DEFAULT_MAX_ITEMS = 200;

    /** @var list<string> 처리 대상 사진 확장자(동영상 제외) */
    protected const PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'heic', 'heif', 'webp', 'gif'];

    public function __construct(
        protected readonly ?ThumbnailGeneratorInterface $thumbnailGenerator = null,
        protected readonly float $maxSpeedKmh = 200.0,
        protected readonly int $maxItems = self::DEFAULT_MAX_ITEMS,
    ) {
    }

    /**
     * @return array{locations: list<PhotoLocation>, totalCandidates: int, capped: bool}
     */
    public function ingest(string $zipPath, int $userId): array
    {
        $extractDir = WRITEPATH . 'uploads/' . $this->extractDirPrefix() . uniqid('', true);
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
     * 임시 압축 해제 디렉터리명 접두사(예: "takeout_", "photos_").
     */
    abstract protected function extractDirPrefix(): string;

    /**
     * 압축 해제된 디렉터리에서 좌표 후보(썸네일 없음)와 썸네일 생성용 원본 경로를 뽑는다.
     *
     * totalCandidates 는 상한 적용 전 후보 파일 수다(capped 판정용).
     *
     * @return array{candidates: list<PhotoLocation>, mediaPaths: array<string, string>, totalCandidates: int}
     */
    abstract protected function extractCandidates(string $dir): array;

    /**
     * @return array{locations: list<PhotoLocation>, totalCandidates: int, capped: bool}
     */
    private function processExtracted(string $dir, int $userId): array
    {
        $extracted = $this->extractCandidates($dir);

        // 이상치를 먼저 걸러, 지도에 실제로 남는 좌표에 대해서만 썸네일을 만든다.
        // (필터링돼 사라질 사진의 썸네일이 디스크에 고아로 남는 것을 방지)
        $kept = $this->filterOutliers($extracted['candidates']);

        return [
            'locations' => $this->attachThumbnails($kept, $extracted['mediaPaths'], $userId),
            'totalCandidates' => $extracted['totalCandidates'],
            'capped' => $extracted['totalCandidates'] > $this->maxItems,
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
    protected function attachThumbnails(array $locations, array $mediaPaths, int $userId): array
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
     * 조건을 만족하는 파일 경로를 재귀 수집해 정렬 반환한다.
     *
     * @param callable(\SplFileInfo): bool $accept
     *
     * @return list<string>
     */
    protected function findFiles(string $dir, callable $accept): array
    {
        $found = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo instanceof \SplFileInfo && $fileInfo->isFile() && $accept($fileInfo)) {
                $found[] = $fileInfo->getPathname();
            }
        }

        sort($found);

        return $found;
    }

    /**
     * 직전 유효 지점 대비 비현실적 이동 속도(기본 200km/h 초과)인 지점을 제외한다.
     *
     * 단, 걸러진 지점 바로 다음 지점이 그 걸러진 지점과 서로 정합하면(같은 지역)
     * 비행기·KTX 등 실제 고속 이동의 도착지로 보고 둘 다 살려 앵커를 옮긴다 —
     * 앵커 고착으로 도착지 동선이 통째로 잘리는 것을 방지한다.
     *
     * 좌표 없는 지점(GPS 없이 촬영 시각만 있는 사진)은 속도 계산 자체가 불가능하므로
     * 항상 유지하고, 앵커(previous)·직전 걸러진 지점(dropped) 갱신에는 관여하지 않는다
     * — 좌표 없는 지점을 몇 개 건너뛰어도 그다음 좌표 있는 지점은 그 이전의 마지막
     * 좌표 있는 지점을 기준으로 이상치 판정을 받는다.
     *
     * @param list<PhotoLocation> $locations
     *
     * @return list<PhotoLocation>
     */
    protected function filterOutliers(array $locations): array
    {
        if (count($locations) <= 1) {
            return $locations;
        }

        usort($locations, static fn (PhotoLocation $a, PhotoLocation $b): int => strcmp($a->takenAt, $b->takenAt));

        $kept = [];
        $previous = null; // 마지막으로 채택된, 좌표 있는 지점(이상치 판정 기준 앵커).
        $dropped = null; // 직전에 걸러진, 좌표 있는 지점 — 후속 지점이 확인해 주면 복권된다.

        foreach ($locations as $current) {
            if ($current->lat === null || $current->lng === null) {
                $kept[] = $current;
                continue;
            }

            if ($previous === null || $this->isReachable($previous, $current)) {
                $kept[] = $current;
                $previous = $current;
                $dropped = null;
                continue;
            }

            if ($dropped !== null && $this->isReachable($dropped, $current)) {
                // 연속 두 지점이 서로 일치 → 실제 이동으로 재인정하고 도착지로 재앵커.
                $kept[] = $dropped;
                $kept[] = $current;
                $previous = $current;
                $dropped = null;
                continue;
            }

            $dropped = $current;
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
