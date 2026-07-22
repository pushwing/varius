<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Ingest\PhotoLocation;
use App\Services\Ingest\TakeoutMetadataParser;
use App\Services\Ingest\ThumbnailGeneratorInterface;

/**
 * 업로드된 Google Takeout zip 을 처리해 동선 좌표를 만드는 서비스.
 *
 * zip 압축 해제 → 사진 ↔ JSON 사이드카 매칭(정확히 일치하는 파일명만) → GPS·촬영시각
 * 파싱 → 200장 상한 → 이상치 필터. 공통 골격은 {@see AbstractZipIngestService} 가 담고,
 * 이 클래스는 "JSON 사이드카에서 좌표를 뽑는" 부분만 구현한다.
 */
class TakeoutIngestService extends AbstractZipIngestService
{
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
        ?ThumbnailGeneratorInterface $thumbnailGenerator = null,
        float $maxSpeedKmh = 200.0,
        int $maxItems = self::DEFAULT_MAX_ITEMS,
    ) {
        parent::__construct($thumbnailGenerator, $maxSpeedKmh, $maxItems);
    }

    protected function extractDirPrefix(): string
    {
        return 'takeout_';
    }

    /**
     * @return array{candidates: list<PhotoLocation>, mediaPaths: array<string, string>, totalCandidates: int}
     */
    protected function extractCandidates(string $dir): array
    {
        $jsonFiles = $this->findFiles(
            $dir,
            static fn (\SplFileInfo $f): bool => strtolower($f->getExtension()) === 'json',
        );

        $candidates = [];
        $mediaPaths = [];
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

        return [
            'candidates' => $candidates,
            'mediaPaths' => $mediaPaths,
            'totalCandidates' => count($jsonFiles),
        ];
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
}
