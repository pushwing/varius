<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Ingest\ExifReaderInterface;
use App\Services\Ingest\PhotoExifParser;
use App\Services\Ingest\PhotoLocation;
use App\Services\Ingest\ThumbnailGeneratorInterface;

/**
 * 일반 압축파일(사진만 든 zip)을 처리해 동선 좌표를 만드는 서비스.
 *
 * Google Takeout 과 달리 사진이 원본이라 GPS 가 사진 자체의 EXIF 에 들어있다.
 * 공통 골격은 {@see AbstractZipIngestService} 가 담고, 이 클래스는 "사진 EXIF 에서
 * 좌표를 뽑는" 부분만 구현한다. EXIF 가 없거나 촬영시각이 없는 사진은 제외한다.
 */
class PlainZipIngestService extends AbstractZipIngestService
{
    public function __construct(
        private readonly PhotoExifParser $exifParser,
        private readonly ExifReaderInterface $exifReader,
        ?ThumbnailGeneratorInterface $thumbnailGenerator = null,
        float $maxSpeedKmh = 200.0,
        int $maxItems = self::DEFAULT_MAX_ITEMS,
    ) {
        parent::__construct($thumbnailGenerator, $maxSpeedKmh, $maxItems);
    }

    protected function extractDirPrefix(): string
    {
        return 'photos_';
    }

    /**
     * @return array{candidates: list<PhotoLocation>, mediaPaths: array<string, string>, totalCandidates: int}
     */
    protected function extractCandidates(string $dir): array
    {
        $photoFiles = $this->findFiles(
            $dir,
            static fn (\SplFileInfo $f): bool => in_array(strtolower($f->getExtension()), self::PHOTO_EXTENSIONS, true),
        );

        $candidates = [];
        $mediaPaths = [];
        foreach (array_slice($photoFiles, 0, $this->maxItems) as $photoPath) {
            $exif = $this->exifReader->read($photoPath);
            if ($exif === null) {
                continue; // EXIF 를 읽을 수 없는 사진(비지원 포맷·손상 등)
            }

            $parsed = $this->exifParser->parse($exif);
            if ($parsed === null || $parsed->takenAt === null) {
                continue; // GPS 또는 촬영시각이 없어 동선에 쓸 수 없음
            }

            $sourceItemId = basename($photoPath);
            $candidates[] = new PhotoLocation($sourceItemId, $parsed->lat, $parsed->lng, $parsed->takenAt);
            $mediaPaths[$sourceItemId] = $photoPath;
        }

        return [
            'candidates' => $candidates,
            'mediaPaths' => $mediaPaths,
            'totalCandidates' => count($photoFiles),
        ];
    }
}
