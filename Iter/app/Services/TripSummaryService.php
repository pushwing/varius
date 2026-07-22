<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PhotoLocationModel;
use App\Support\TimeConverter;

/**
 * 여행(날짜 범위)의 날짜별 사진 요약 — 여행 상세·공개 공유 페이지가 공용으로 사용한다.
 *
 * 사진 id 만 반환하고 썸네일 URL 프리픽스는 호출측이 붙인다 — 로그인 전용 URL
 * (/thumbnails/{id})과 공개 URL(/t/{token}/thumbnails/{id}) 양쪽에서 재사용하기 위함.
 */
final class TripSummaryService
{
    /** 날짜당 노출할 최대 썸네일 후보 수(공개 페이지 그리드·커버 선택지 과다 방지). */
    private const MAX_THUMBNAILS_PER_DAY = 6;

    public function __construct(
        private readonly PhotoLocationModel $photos,
    ) {
    }

    /**
     * 기간(KST) 내 사진을 날짜별로 묶는다.
     *
     * @return list<array{date: string, photo_count: int, thumbnail_ids: list<int>}>
     */
    public function buildDaySummaries(int $userId, string $startDate, string $endDate): array
    {
        [$startUtc] = TimeConverter::kstDateToUtcRange($startDate);
        [, $endUtc] = TimeConverter::kstDateToUtcRange($endDate);

        return $this->buildDaySummariesFromRows($this->photos->findByUserBetween($userId, $startUtc, $endUtc));
    }

    /**
     * 이미 조회된 좌표 행으로 날짜별 요약을 만드는 순수 로직. buildDaySummaries() 의
     * 실제 계산부이며, TripController::showData() 처럼 사진 조회를 이미 한 번 수행한
     * 호출측이 중복 조회 없이 재사용하기 위해 공개 메서드로 노출한다.
     *
     * @param list<array<string, mixed>> $rows PhotoLocationModel::findByUserBetween() 과
     *                                          같은 형태.
     *
     * @return list<array{date: string, photo_count: int, thumbnail_ids: list<int>}>
     */
    public function buildDaySummariesFromRows(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $takenAt = (string) ($row['taken_at'] ?? '');
            if ($takenAt === '') {
                continue;
            }

            $date = substr(TimeConverter::utcToKst($takenAt), 0, 10);
            if (! isset($grouped[$date])) {
                $grouped[$date] = ['photo_count' => 0, 'thumbnail_ids' => []];
            }

            $grouped[$date]['photo_count']++;

            $path = (string) ($row['thumbnail_path'] ?? '');
            if ($path !== '' && count($grouped[$date]['thumbnail_ids']) < self::MAX_THUMBNAILS_PER_DAY) {
                $grouped[$date]['thumbnail_ids'][] = (int) ($row['id'] ?? 0);
            }
        }

        ksort($grouped);

        $days = [];
        foreach ($grouped as $date => $summary) {
            $days[] = [
                'date' => $date,
                'photo_count' => $summary['photo_count'],
                'thumbnail_ids' => $summary['thumbnail_ids'],
            ];
        }

        return $days;
    }

    /**
     * 여행 커버 사진 id 를 정한다. 저장된 값이 있으면 그대로 신뢰하고(사진이 삭제되면
     * FK ON DELETE SET NULL 로 자동으로 비워지므로 별도 소유권 재검증이 필요 없다),
     * 없으면 기간 내 가장 이른 사진(썸네일 있는 것)으로 대체한다.
     */
    public function resolveCoverId(?int $storedCoverId, int $userId, string $startDate, string $endDate): ?int
    {
        if ($storedCoverId !== null) {
            return $storedCoverId;
        }

        [$startUtc] = TimeConverter::kstDateToUtcRange($startDate);
        [, $endUtc] = TimeConverter::kstDateToUtcRange($endDate);

        return $this->photos->firstThumbnailBetween($userId, $startUtc, $endUtc);
    }
}
