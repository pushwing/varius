# 여행 통계 후속 개선(중복 쿼리 제거 + 논제로 거리 테스트) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `TripController::showData()`가 사진 좌표를 요청당 정확히 1회만 조회하도록
`TripStatsService`·`TripSummaryService`에 순수 계산 메서드를 추출하고, `distance_km`이
0이 아닌 경우를 검증하는 HTTP 레벨 테스트를 추가한다.

**Architecture:** `TripStatsService`·`TripSummaryService` 각각에 "이미 조회된 rows를
받는" 순수 메서드(`buildStatsFromRows()`/`buildDaySummariesFromRows()`)를 신설하고,
기존 공개 메서드(`buildStats()`/`buildDaySummaries()`)는 조회 후 그 순수 메서드에
위임하는 얇은 래퍼로 축소한다. `TripController::showData()`만 `PhotoLocationModel::
findByUserBetween()`을 직접 한 번 호출해 두 서비스의 `*FromRows` 메서드에 전달한다.

**Tech Stack:** PHP 8.4+ / CodeIgniter 4, PHPUnit 10.5(in-memory SQLite), PHPStan 레벨 6.

## Global Constraints

- `declare(strict_types=1)`, PSR-12, PHPStan 레벨 6 통과 필수.
- 새 public 메서드는 제네릭 타입(`list<array{...}>` 등) PHPDoc 명시 필수.
- TDD 순서 엄수(RED → GREEN → 커밋) — **단, Task 3의 컨트롤러 리팩터링 자체는 순수
  동작 보존 리팩터링(응답 JSON 형태 불변)이라 새 실패 테스트를 만들 여지가 없다.**
  Task 3에서는 먼저 신규 e2e 테스트를 추가해 리팩터링 전 코드로 이미 통과함을 확인한
  뒤(회귀 안전망), 컨트롤러를 리팩터링하고 전체 스위트가 여전히 통과하는지로 검증한다.
- 커밋 메시지: 이모지 + Conventional Commits 접두어 + 한국어 설명.
- `TripShareController`, `TripController::data()`(목록 페이지 커버 사진 결정 경로)는
  이번 변경과 무관 — 기존 공개 시그니처(`buildDaySummaries(userId, ...)`)를 그대로
  사용하므로 손대지 않는다.
- 새 라우트·마이그레이션 없음. 응답 JSON 형태 변경 없음(순수 내부 리팩터링).

---

### Task 1: TripStatsService — buildStatsFromRows 추출

**Files:**
- Modify: `app/Services/TripStatsService.php`(전체 교체)
- Test: `tests/unit/TripStatsServiceTest.php`(기존 4개 테스트 유지, 신규 4개 추가)

**Interfaces:**
- Produces: `App\Services\TripStatsService::buildStatsFromRows(array $rows): array{distance_km: float, spot_count: int}`
  — `$rows`는 `list<array<string, mixed>>` (`PhotoLocationModel::findByUserBetween()`과
  같은 형태: `lat`, `lng`, `taken_at` 등 키를 가진 배열, `taken_at` 오름차순 정렬 보장
  필요). Task 3이 이 메서드를 직접 호출한다.

- [ ] **Step 1: 실패하는 테스트 작성**

`tests/unit/TripStatsServiceTest.php` 파일 끝(77번째 줄 `}`, 클래스 닫는 중괄호 직전)에
다음 4개 테스트 메서드를 추가한다:

```php
    public function testFromRowsReturnsZeroesWhenNoPhotos(): void
    {
        $service = new TripStatsService($this->createMock(PhotoLocationModel::class));

        $stats = $service->buildStatsFromRows([]);

        $this->assertSame(0.0, $stats['distance_km']);
        $this->assertSame(0, $stats['spot_count']);
    }

    public function testFromRowsSinglePhotoHasZeroDistanceAndOneSpot(): void
    {
        $service = new TripStatsService($this->createMock(PhotoLocationModel::class));

        $stats = $service->buildStatsFromRows([
            ['lat' => '37.5665', 'lng' => '126.9780', 'taken_at' => '2024-03-15 01:00:00'],
        ]);

        $this->assertSame(0.0, $stats['distance_km']);
        $this->assertSame(1, $stats['spot_count']);
    }

    public function testFromRowsAccumulatesDistanceInChronologicalOrderAndCountsSpots(): void
    {
        // 서울시청 → 강남역 → 잠실, 세 지점 모두 서로 30m 반경 밖(별개 지점).
        $p1 = ['lat' => 37.5665, 'lng' => 126.9780];
        $p2 = ['lat' => 37.4979, 'lng' => 127.0276];
        $p3 = ['lat' => 37.5133, 'lng' => 127.1000];

        $service = new TripStatsService($this->createMock(PhotoLocationModel::class));

        $stats = $service->buildStatsFromRows([
            ['lat' => (string) $p1['lat'], 'lng' => (string) $p1['lng'], 'taken_at' => '2024-03-15 01:00:00'],
            ['lat' => (string) $p2['lat'], 'lng' => (string) $p2['lng'], 'taken_at' => '2024-03-15 02:00:00'],
            ['lat' => (string) $p3['lat'], 'lng' => (string) $p3['lng'], 'taken_at' => '2024-03-15 03:00:00'],
        ]);

        $expectedDistance = GeoDistanceCalculator::kilometers($p1['lat'], $p1['lng'], $p2['lat'], $p2['lng'])
            + GeoDistanceCalculator::kilometers($p2['lat'], $p2['lng'], $p3['lat'], $p3['lng']);

        $this->assertEqualsWithDelta($expectedDistance, $stats['distance_km'], 0.0001);
        $this->assertSame(3, $stats['spot_count']);
    }

    public function testFromRowsPhotosWithinClusterRadiusCountAsOneSpotWithNearZeroDistance(): void
    {
        $service = new TripStatsService($this->createMock(PhotoLocationModel::class));

        $stats = $service->buildStatsFromRows([
            ['lat' => '37.5665', 'lng' => '126.9780', 'taken_at' => '2024-03-15 01:00:00'],
            ['lat' => '37.5665', 'lng' => '126.9780', 'taken_at' => '2024-03-15 02:00:00'],
        ]);

        $this->assertSame(0.0, $stats['distance_km']);
        $this->assertSame(1, $stats['spot_count']);
    }
```

- [ ] **Step 2: 테스트가 실패하는지 확인**

Run: `vendor/bin/phpunit --filter TripStatsServiceTest tests/unit/TripStatsServiceTest.php`
Expected: FAIL — `Call to undefined method App\Services\TripStatsService::buildStatsFromRows()`
(4건, 기존 4개 테스트는 여전히 PASS)

- [ ] **Step 3: 최소 구현 작성**

`app/Services/TripStatsService.php` 전체를 다음으로 교체한다:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PhotoLocationModel;
use App\Support\TimeConverter;

/**
 * 여행(날짜 범위)의 이동거리·방문 지점 수 통계 — 여행 상세 페이지가 사용한다.
 */
final class TripStatsService
{
    public function __construct(
        private readonly PhotoLocationModel $photos,
    ) {
    }

    /**
     * 기간(KST) 내 사진을 촬영 순서대로 이어 총 이동거리와 방문 지점 수를 계산한다.
     *
     * @return array{distance_km: float, spot_count: int}
     */
    public function buildStats(int $userId, string $startDate, string $endDate): array
    {
        [$startUtc] = TimeConverter::kstDateToUtcRange($startDate);
        [, $endUtc] = TimeConverter::kstDateToUtcRange($endDate);

        return $this->buildStatsFromRows($this->photos->findByUserBetween($userId, $startUtc, $endUtc));
    }

    /**
     * 이미 조회된 좌표 행(taken_at 오름차순)으로 이동거리·방문 지점 수를 계산하는 순수
     * 로직. buildStats() 의 실제 계산부이며, TripController::showData() 처럼 사진 조회를
     * 이미 한 번 수행한 호출측이 중복 조회 없이 재사용하기 위해 공개 메서드로 노출한다.
     *
     * @param list<array<string, mixed>> $rows PhotoLocationModel::findByUserBetween() 과
     *                                          같은 형태(id, source_item_id, lat, lng,
     *                                          thumbnail_path, taken_at), taken_at
     *                                          오름차순 정렬이 보장돼야 한다.
     *
     * @return array{distance_km: float, spot_count: int}
     */
    public function buildStatsFromRows(array $rows): array
    {
        $points = [];
        foreach ($rows as $row) {
            $points[] = ['lat' => (float) ($row['lat'] ?? 0), 'lng' => (float) ($row['lng'] ?? 0)];
        }

        $distanceKm = 0.0;
        for ($i = 1, $count = count($points); $i < $count; $i++) {
            $distanceKm += GeoDistanceCalculator::kilometers(
                $points[$i - 1]['lat'],
                $points[$i - 1]['lng'],
                $points[$i]['lat'],
                $points[$i]['lng'],
            );
        }

        return [
            'distance_km' => $distanceKm,
            'spot_count' => PointClusterer::countClusters($points),
        ];
    }
}
```

- [ ] **Step 4: 테스트 통과 확인**

Run: `vendor/bin/phpunit tests/unit/TripStatsServiceTest.php`
Expected: `OK (8 tests, ...)` (기존 4개 + 신규 4개)

- [ ] **Step 5: 정적 분석 통과 확인**

Run: `composer analyse`
Expected: `[OK] No errors`

- [ ] **Step 6: 커밋**

```bash
git add app/Services/TripStatsService.php tests/unit/TripStatsServiceTest.php
git commit -m "$(cat <<'EOF'
♻️ refactor: TripStatsService 에 rows 기반 순수 계산 메서드 추출

EOF
)"
```

---

### Task 2: TripSummaryService — buildDaySummariesFromRows 추출

**Files:**
- Modify: `app/Services/TripSummaryService.php`(전체 교체)
- Test: `tests/unit/TripSummaryServiceTest.php`(기존 5개 테스트 유지, 신규 3개 추가)

**Interfaces:**
- Produces: `App\Services\TripSummaryService::buildDaySummariesFromRows(array $rows): list<array{date: string, photo_count: int, thumbnail_ids: list<int>}>`
  — `$rows`는 Task 1과 동일한 형태(`PhotoLocationModel::findByUserBetween()` 결과).
  Task 3이 이 메서드를 직접 호출한다.

- [ ] **Step 1: 실패하는 테스트 작성**

`tests/unit/TripSummaryServiceTest.php` 파일 끝(85번째 줄 `}`, 클래스 닫는 중괄호
직전)에 다음 3개 테스트 메서드를 추가한다:

```php
    public function testFromRowsGroupsPhotosByKstDateWithThumbnailIds(): void
    {
        $service = new TripSummaryService($this->createMock(PhotoLocationModel::class));

        $days = $service->buildDaySummariesFromRows([
            ['id' => 1, 'lat' => '37.5', 'lng' => '127.0', 'thumbnail_path' => '/t/1.jpg', 'taken_at' => '2024-03-15 01:00:00'], // KST 3/15 10:00
            ['id' => 2, 'lat' => '37.5', 'lng' => '127.0', 'thumbnail_path' => null, 'taken_at' => '2024-03-15 02:00:00'],       // KST 3/15 11:00, 썸네일 없음
            ['id' => 3, 'lat' => '37.5', 'lng' => '127.0', 'thumbnail_path' => '/t/3.jpg', 'taken_at' => '2024-03-15 23:30:00'], // KST 3/16 08:30
        ]);

        $this->assertCount(2, $days);
        $this->assertSame('2024-03-15', $days[0]['date']);
        $this->assertSame(2, $days[0]['photo_count']);
        $this->assertSame([1], $days[0]['thumbnail_ids']); // 썸네일 없는 사진은 제외.
        $this->assertSame('2024-03-16', $days[1]['date']);
        $this->assertSame(1, $days[1]['photo_count']);
        $this->assertSame([3], $days[1]['thumbnail_ids']);
    }

    public function testFromRowsCapsThumbnailIdsAtSixPerDay(): void
    {
        $rows = [];
        for ($i = 1; $i <= 8; $i++) {
            $rows[] = ['id' => $i, 'lat' => '37.5', 'lng' => '127.0', 'thumbnail_path' => '/t/' . $i . '.jpg', 'taken_at' => sprintf('2024-03-15 0%d:00:00', $i)];
        }

        $service = new TripSummaryService($this->createMock(PhotoLocationModel::class));
        $days = $service->buildDaySummariesFromRows($rows);

        $this->assertCount(1, $days);
        $this->assertCount(6, $days[0]['thumbnail_ids']);
        $this->assertSame(8, $days[0]['photo_count']); // 개수는 상한 없이 전부 센다.
    }

    public function testFromRowsEmptyRowsReturnsEmptyList(): void
    {
        $service = new TripSummaryService($this->createMock(PhotoLocationModel::class));

        $this->assertSame([], $service->buildDaySummariesFromRows([]));
    }
```

- [ ] **Step 2: 테스트가 실패하는지 확인**

Run: `vendor/bin/phpunit tests/unit/TripSummaryServiceTest.php`
Expected: FAIL — `Call to undefined method App\Services\TripSummaryService::buildDaySummariesFromRows()`
(3건, 기존 5개 테스트는 여전히 PASS)

- [ ] **Step 3: 최소 구현 작성**

`app/Services/TripSummaryService.php` 전체를 다음으로 교체한다:

```php
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
```

- [ ] **Step 4: 테스트 통과 확인**

Run: `vendor/bin/phpunit tests/unit/TripSummaryServiceTest.php`
Expected: `OK (8 tests, ...)` (기존 5개 + 신규 3개)

- [ ] **Step 5: 정적 분석 통과 확인**

Run: `composer analyse`
Expected: `[OK] No errors`

- [ ] **Step 6: 커밋**

```bash
git add app/Services/TripSummaryService.php tests/unit/TripSummaryServiceTest.php
git commit -m "$(cat <<'EOF'
♻️ refactor: TripSummaryService 에 rows 기반 순수 계산 메서드 추출

EOF
)"
```

---

### Task 3: TripController::showData() 리팩터링 + 논제로 거리 e2e 테스트

**Files:**
- Modify: `app/Controllers/TripController.php:173-220`(`showData()` 메서드)
- Modify: `tests/feature/TripControllerTest.php`(`testShowDataReturnsTripAndDaySummaries` 옆에 신규 테스트 추가)

**Interfaces:**
- Consumes: `App\Services\TripStatsService::buildStatsFromRows(array $rows): array{distance_km: float, spot_count: int}`
  (Task 1), `App\Services\TripSummaryService::buildDaySummariesFromRows(array $rows): array`
  (Task 2), `App\Models\PhotoLocationModel::findByUserBetween(int $userId, string $startUtc, string $endUtc): array`
  (기존, 무변경).

**중요:** 이 태스크의 컨트롤러 변경은 순수 리팩터링(응답 JSON 형태 불변)이라 고전적인
RED 단계가 성립하지 않는다. Step 1의 신규 e2e 테스트는 **리팩터링 전 코드로 이미
통과해야 정상**이다 — "새 기능이 아직 없어 실패"가 아니라 "기존 코드가 이미 올바르게
동작함을 확인하는 회귀 안전망"이라는 뜻이다. Step 3에서 리팩터링 후 같은 테스트가
여전히 통과하는지로 "행동이 보존됐는지"를 검증한다.

- [ ] **Step 1: 논제로 거리를 검증하는 e2e 테스트 작성**

`tests/feature/TripControllerTest.php`의 `testShowDataReturnsTripAndDaySummaries()`
메서드(215-233번째 줄) 바로 다음에 다음 테스트를 추가한다:

```php
    public function testShowDataReturnsNonZeroDistanceWhenPhotosAreFarApart(): void
    {
        (new PhotoLocationModel())->saveMany($this->userId, [
            new PhotoLocation('nz1', 37.5665, 126.9780, '2024-03-15 01:00:00'), // 서울시청
            new PhotoLocation('nz2', 37.4979, 127.0276, '2024-03-15 02:00:00'), // 강남역
        ]);
        $tripId = (int) (new TripModel())->insert([
            'user_id' => $this->userId, 'title' => 'T', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-15', 'cover_photo_id' => null,
        ]);

        $result = $this->withSession(['user_id' => $this->userId])->get('trips/' . $tripId . '/data');

        $result->assertStatus(200);
        $data = json_decode($result->getJSON() ?? '', true);
        $this->assertGreaterThan(0.0, (float) $data['stats']['distance_km']);
        $this->assertSame(2, $data['stats']['spot_count']);
    }
```

- [ ] **Step 2: 리팩터링 전 코드로 이미 통과하는지 확인(회귀 안전망 확보)**

Run: `vendor/bin/phpunit --filter testShowDataReturnsNonZeroDistanceWhenPhotosAreFarApart tests/feature/TripControllerTest.php`
Expected: `OK (1 test, ...)` — 아직 컨트롤러를 리팩터링하지 않았으므로 기존
`TripStatsService::buildStats()`(자체 조회 버전)가 그대로 호출돼 이미 정확한 값을
반환한다. 이 시점에 실패한다면 테스트 자체가 잘못된 것이므로 리팩터링을 시작하기 전에
먼저 원인을 파악해야 한다.

- [ ] **Step 3: TripController::showData() 를 rows 1회 조회 방식으로 리팩터링**

`app/Controllers/TripController.php`의 `showData()` 메서드(173-220번째 줄)를 다음으로
교체한다:

```php
    /**
     * 여행 상세 데이터(JSON, GET /trips/{id}/data).
     */
    public function showData(int $id): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => '로그인이 필요합니다.']);
        }

        $trip = model(TripModel::class)->findOwned($id, $userId);
        if ($trip === null) {
            return $this->response->setStatusCode(404);
        }

        $startDate = (string) $trip['start_date'];
        $endDate = (string) $trip['end_date'];
        $summaryService = service('tripSummary');

        $storedCoverId = $trip['cover_photo_id'] !== null ? (int) $trip['cover_photo_id'] : null;
        $coverId = $summaryService->resolveCoverId($storedCoverId, $userId, $startDate, $endDate);

        [$startUtc] = TimeConverter::kstDateToUtcRange($startDate);
        [, $endUtc] = TimeConverter::kstDateToUtcRange($endDate);
        $rows = model(PhotoLocationModel::class)->findByUserBetween($userId, $startUtc, $endUtc);

        $days = [];
        foreach ($summaryService->buildDaySummariesFromRows($rows) as $summary) {
            $firstId = $summary['thumbnail_ids'][0] ?? null;
            $days[] = [
                'date' => $summary['date'],
                'photo_count' => $summary['photo_count'],
                'first_photo_id' => $firstId,
                'first_thumbnail_url' => $firstId !== null ? '/thumbnails/' . $firstId : null,
            ];
        }

        $stats = service('tripStats')->buildStatsFromRows($rows);

        return $this->response->setJSON([
            'trip' => [
                'id' => (int) $trip['id'],
                'title' => (string) $trip['title'],
                'body' => (string) ($trip['body'] ?? ''),
                'start_date' => $startDate,
                'end_date' => $endDate,
                'cover_photo_id' => $storedCoverId,
                'cover_thumbnail_url' => $coverId !== null ? '/thumbnails/' . $coverId : null,
            ],
            'days' => $days,
            'stats' => [
                'distance_km' => round($stats['distance_km'], 1),
                'spot_count' => $stats['spot_count'],
            ],
        ]);
    }
```

(`model(TripModel::class)`, `TimeConverter`, `PhotoLocationModel`은 이미 파일 상단에
`use`돼 있으므로 추가 import는 필요 없다.)

- [ ] **Step 4: 전체 테스트로 회귀 없음 확인**

Run: `vendor/bin/phpunit tests/feature/TripControllerTest.php`
Expected: `OK (27 tests, ...)` (기존 26개 + 신규 1개), 특히
`testShowDataReturnsTripAndDaySummaries`와 `testShowDataReturnsNonZeroDistanceWhenPhotosAreFarApart`
둘 다 여전히 PASS — 리팩터링이 응답 형태를 바꾸지 않았다는 증거.

- [ ] **Step 5: composer ci 전체 검증**

Run: `composer ci`
Expected: CS Fixer 통과, `[OK] No errors`(PHPStan), `OK (264 tests, ...)`(PHPUnit —
기존 256개 + Task 1의 신규 4개 + Task 2의 신규 3개 + Task 3의 신규 1개 = 264개).

- [ ] **Step 6: 커밋**

```bash
git add app/Controllers/TripController.php tests/feature/TripControllerTest.php
git commit -m "$(cat <<'EOF'
♻️ refactor: 여행 상세 조회 시 사진 좌표 중복 쿼리 제거

EOF
)"
```
