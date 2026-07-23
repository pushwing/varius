# 연간 통계·캘린더 히트맵 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** "내 여행" 페이지(`/trips`)에 올해의 총 여행일수, GitHub 잔디 스타일 캘린더 히트맵, 가장
많이 방문한 지점(대표 사진 + 방문 횟수)을 보여주는 새 섹션을 추가한다.

**Architecture:** `TripModel`에 연도 겹침 조회 메서드를 추가하고, 신규 `TripYearlyStatsService`가
저장된 여행의 날짜를 연도로 잘라 합산하고 기존 `PointClusterer`로 그 해 좌표를 클러스터링해
최다 방문 지점을 뽑는다. `TripController::stats()`가 이를 JSON으로 노출하고, `trips.php`가
순수 CSS Grid로 잔디 그리드를 그린다.

**Tech Stack:** PHP 8.4+ / CodeIgniter 4, PHPUnit 10.5(in-memory SQLite), PHPStan 레벨 6, 바닐라 JS.

## Global Constraints

- `declare(strict_types=1)`, PSR-12, PHPStan 레벨 6 통과 필수.
- 신규 메서드는 제네릭 타입(`list<array{...}>` 등) PHPDoc 명시 필수.
- TDD 순서 엄수: RED(실패 확인) → GREEN(최소 구현) → 커밋.
- 커밋 메시지: 이모지 + Conventional Commits 접두어 + 한국어 설명 + `(#29)`.
- **"가장 많이 간 도시"는 실제 지명이 아니라 "가장 많이 방문한 좌표 지점"** 으로 구현한다(역지오코딩
  인프라 없음 — 스펙 결정사항).
- **여행일수는 저장된 `trips` 레코드의 날짜 범위만** 합산한다(사진만 있고 저장 안 한 날짜는 제외).
- 히트맵은 **올해(KST 기준)만 고정 표시**한다 — 연도 선택 UI 없음.
- 새 마이그레이션 없음(기존 `trips`, `photo_locations` 테이블만 사용).
- `trips/stats` 라우트는 기존 `trips/data`와 동일한 이유로 `trips/(:num)` 라우트보다 먼저 선언한다.

---

### Task 1: TripModel — findByUserInYear() 추가

**Files:**
- Modify: `app/Models/TripModel.php`(전체 교체)
- Test: `tests/database/TripModelTest.php`(파일 끝에 3개 테스트 추가)

**Interfaces:**
- Produces: `App\Models\TripModel::findByUserInYear(int $userId, int $year): list<array<string, mixed>>`
  — 그 해(KST)와 겹치는 여행을 `start_date` 오름차순으로 반환. Task 2가 이 메서드를 사용한다.

- [ ] **Step 1: 실패하는 테스트 작성**

`tests/database/TripModelTest.php` 파일 끝(133번째 줄 `}`, 클래스 닫는 중괄호 직전)에 다음
3개 테스트를 추가한다:

```php
    public function testFindByUserInYearIncludesTripsFullyWithinYear(): void
    {
        $model = new TripModel();
        $model->insert([
            'user_id' => $this->userId, 'title' => 'T', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-17', 'cover_photo_id' => null,
        ]);

        $this->assertCount(1, $model->findByUserInYear($this->userId, 2024));
    }

    public function testFindByUserInYearIncludesTripsCrossingYearBoundary(): void
    {
        $model = new TripModel();
        $model->insert([
            'user_id' => $this->userId, 'title' => 'T', 'body' => '',
            'start_date' => '2024-12-30', 'end_date' => '2025-01-02', 'cover_photo_id' => null,
        ]);

        $this->assertCount(1, $model->findByUserInYear($this->userId, 2024));
        $this->assertCount(1, $model->findByUserInYear($this->userId, 2025));
    }

    public function testFindByUserInYearExcludesTripsInOtherYears(): void
    {
        $model = new TripModel();
        $model->insert([
            'user_id' => $this->userId, 'title' => 'T', 'body' => '',
            'start_date' => '2023-03-15', 'end_date' => '2023-03-17', 'cover_photo_id' => null,
        ]);

        $this->assertCount(0, $model->findByUserInYear($this->userId, 2024));
    }
```

- [ ] **Step 2: 테스트가 실패하는지 확인**

Run: `vendor/bin/phpunit --filter TripModelTest tests/database/TripModelTest.php`
Expected: FAIL — `Call to undefined method App\Models\TripModel::findByUserInYear()`(3건, 기존
6개 테스트는 여전히 PASS)

- [ ] **Step 3: 최소 구현 작성**

`app/Models/TripModel.php` 전체를 다음으로 교체한다:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class TripModel extends Model
{
    protected $table = 'trips';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;

    /**
     * @var list<string>
     */
    protected $allowedFields = [
        'user_id',
        'title',
        'body',
        'start_date',
        'end_date',
        'cover_photo_id',
    ];

    /**
     * 사용자의 여행을 최신 시작일순으로 조회한다.
     *
     * @return list<array<string, mixed>>
     */
    public function findByUserOrdered(int $userId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->where('user_id', $userId)
            ->orderBy('start_date', 'DESC')
            ->findAll();

        return $rows;
    }

    /**
     * 여행이 이 사용자 소유일 때만 반환한다(다른 사용자 접근 방지).
     *
     * @return array<string, mixed>|null
     */
    public function findOwned(int $id, int $userId): ?array
    {
        /** @var array<string, mixed>|null $row */
        $row = $this->where('id', $id)
            ->where('user_id', $userId)
            ->first();

        return $row;
    }

    /**
     * 주어진 기간이 이 사용자의 기존 여행과 겹치는지 확인한다(표준 구간 겹침 공식).
     *
     * @param int|null $excludeId 수정 시 자기 자신을 제외하기 위한 여행 id
     */
    public function overlaps(int $userId, string $startDate, string $endDate, ?int $excludeId = null): bool
    {
        $builder = $this->where('user_id', $userId)
            ->where('start_date <=', $endDate)
            ->where('end_date >=', $startDate);

        if ($excludeId !== null) {
            $builder->where('id !=', $excludeId);
        }

        return $builder->countAllResults() > 0;
    }

    /**
     * 그 해(KST)와 겹치는 여행을 조회한다(연간 통계용) — start_date 오름차순.
     *
     * @return list<array<string, mixed>>
     */
    public function findByUserInYear(int $userId, int $year): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->where('user_id', $userId)
            ->where('start_date <=', $year . '-12-31')
            ->where('end_date >=', $year . '-01-01')
            ->orderBy('start_date', 'ASC')
            ->findAll();

        return $rows;
    }
}
```

- [ ] **Step 4: 테스트 통과 확인**

Run: `vendor/bin/phpunit tests/database/TripModelTest.php`
Expected: `OK (9 tests, ...)` — 기존 6개 + 신규 3개.

- [ ] **Step 5: 정적 분석 통과 확인**

Run: `vendor/bin/phpstan analyse --ansi --memory-limit=1G`
Expected: `[OK] No errors`

- [ ] **Step 6: 커밋**

```bash
git add app/Models/TripModel.php tests/database/TripModelTest.php
git commit -m "$(cat <<'EOF'
✨ feat: TripModel 에 연도 겹침 조회 메서드 추가 (#29)

EOF
)"
```

---

### Task 2: TripYearlyStatsService 신설 + Services.php 등록

**Files:**
- Create: `app/Services/TripYearlyStatsService.php`
- Test: `tests/unit/TripYearlyStatsServiceTest.php`
- Modify: `app/Config/Services.php`(팩토리 메서드 추가)

**Interfaces:**
- Consumes: `App\Models\TripModel::findByUserInYear(int, int): list<array<string, mixed>>`(Task 1),
  `App\Models\PhotoLocationModel::findByUserBetween(int, string, string): array`(기존),
  `App\Services\PointClusterer::assignClusters(array $points): list<int>`(기존).
- Produces: `App\Services\TripYearlyStatsService::buildForYear(int $userId, int $year): array{travel_days: int, heatmap_dates: list<string>, top_spot: array{lat: float, lng: float, visit_count: int, thumbnail_url: string|null}|null}`.
  Task 3이 `service('tripYearlyStats')->buildForYear(...)`로 호출한다.

- [ ] **Step 1: 실패하는 테스트 작성**

`tests/unit/TripYearlyStatsServiceTest.php`(신규):

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PhotoLocationModel;
use App\Models\TripModel;
use App\Services\TripYearlyStatsService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TripYearlyStatsServiceTest extends CIUnitTestCase
{
    /**
     * @param list<array<string, mixed>> $trips
     * @param list<array<string, mixed>> $photoRows
     */
    private function service(array $trips, array $photoRows = []): TripYearlyStatsService
    {
        $tripModel = $this->createMock(TripModel::class);
        $tripModel->method('findByUserInYear')->willReturn($trips);

        $photoModel = $this->createMock(PhotoLocationModel::class);
        $photoModel->method('findByUserBetween')->willReturn($photoRows);

        return new TripYearlyStatsService($tripModel, $photoModel);
    }

    public function testSumsNonOverlappingTripDates(): void
    {
        $stats = $this->service([
            ['start_date' => '2024-03-15', 'end_date' => '2024-03-17'],
            ['start_date' => '2024-05-01', 'end_date' => '2024-05-01'],
        ])->buildForYear(1, 2024);

        $this->assertSame(4, $stats['travel_days']);
        $this->assertSame(['2024-03-15', '2024-03-16', '2024-03-17', '2024-05-01'], $stats['heatmap_dates']);
    }

    public function testClampsTripDatesCrossingYearBoundary(): void
    {
        $stats = $this->service([
            ['start_date' => '2024-12-30', 'end_date' => '2025-01-02'],
        ])->buildForYear(1, 2024);

        $this->assertSame(2, $stats['travel_days']);
        $this->assertSame(['2024-12-30', '2024-12-31'], $stats['heatmap_dates']);
    }

    public function testReturnsZeroTravelDaysWhenNoTrips(): void
    {
        $stats = $this->service([])->buildForYear(1, 2024);

        $this->assertSame(0, $stats['travel_days']);
        $this->assertSame([], $stats['heatmap_dates']);
    }

    public function testReturnsNullTopSpotWhenNoPhotos(): void
    {
        $stats = $this->service([], [])->buildForYear(1, 2024);

        $this->assertNull($stats['top_spot']);
    }

    public function testReturnsNullTopSpotWhenAllPhotosLackCoordinates(): void
    {
        $stats = $this->service([], [
            ['id' => 1, 'lat' => null, 'lng' => null, 'thumbnail_path' => null, 'taken_at' => '2024-03-15 01:00:00'],
        ])->buildForYear(1, 2024);

        $this->assertNull($stats['top_spot']);
    }

    public function testFindsMostVisitedClusterAsTopSpot(): void
    {
        $stats = $this->service([], [
            // 서울시청 근처 2장(클러스터 A, 약 15m 이내) + 부산 1장(클러스터 B) → A가 최다.
            ['id' => 1, 'lat' => '37.5665', 'lng' => '126.9780', 'thumbnail_path' => '/t/1.jpg', 'taken_at' => '2024-03-15 01:00:00'],
            ['id' => 2, 'lat' => '37.5666', 'lng' => '126.9781', 'thumbnail_path' => null, 'taken_at' => '2024-03-15 02:00:00'],
            ['id' => 3, 'lat' => '35.1796', 'lng' => '129.0756', 'thumbnail_path' => null, 'taken_at' => '2024-03-16 01:00:00'],
        ])->buildForYear(1, 2024);

        $this->assertNotNull($stats['top_spot']);
        $this->assertSame(2, $stats['top_spot']['visit_count']);
        $this->assertEqualsWithDelta(37.5665, $stats['top_spot']['lat'], 0.001);
        $this->assertSame('/thumbnails/1', $stats['top_spot']['thumbnail_url']);
    }
}
```

- [ ] **Step 2: 테스트가 실패하는지 확인**

Run: `vendor/bin/phpunit tests/unit/TripYearlyStatsServiceTest.php`
Expected: FAIL — `Class "App\Services\TripYearlyStatsService" not found`(6건)

- [ ] **Step 3: 최소 구현 작성**

`app/Services/TripYearlyStatsService.php`(신규):

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PhotoLocationModel;
use App\Models\TripModel;
use App\Support\TimeConverter;

/**
 * 연간 통계 — 그 해(KST)의 총 여행일수·캘린더 히트맵용 날짜 목록·가장 많이 방문한 지점을
 * 계산한다. "가장 많이 간 도시"는 역지오코딩 인프라가 없어 실제 지명 대신 PointClusterer
 * 좌표 클러스터로 대체한다(Iter/docs/superpowers/specs/2026-07-23-trip-yearly-heatmap-design.md 참고).
 */
final class TripYearlyStatsService
{
    public function __construct(
        private readonly TripModel $trips,
        private readonly PhotoLocationModel $photos,
    ) {
    }

    /**
     * @return array{travel_days: int, heatmap_dates: list<string>, top_spot: array{lat: float, lng: float, visit_count: int, thumbnail_url: string|null}|null}
     */
    public function buildForYear(int $userId, int $year): array
    {
        $travelDates = $this->collectTravelDates($userId, $year);

        return [
            'travel_days' => count($travelDates),
            'heatmap_dates' => $travelDates,
            'top_spot' => $this->findTopSpot($userId, $year),
        ];
    }

    /**
     * 저장된 여행들의 날짜 범위를 그 해로 잘라(clamp) 중복 없는 날짜 목록을 만든다.
     *
     * @return list<string>
     */
    private function collectTravelDates(int $userId, int $year): array
    {
        $yearStart = $year . '-01-01';
        $yearEnd = $year . '-12-31';

        $dates = [];
        foreach ($this->trips->findByUserInYear($userId, $year) as $trip) {
            $start = max((string) $trip['start_date'], $yearStart);
            $end = min((string) $trip['end_date'], $yearEnd);

            $cursor = $start;
            while ($cursor <= $end) {
                $dates[$cursor] = true;
                $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
            }
        }

        $sorted = array_keys($dates);
        sort($sorted);

        return $sorted;
    }

    /**
     * 그 해(KST) 좌표를 클러스터링해 사진 수가 가장 많은 지점을 찾는다.
     *
     * @return array{lat: float, lng: float, visit_count: int, thumbnail_url: string|null}|null
     */
    private function findTopSpot(int $userId, int $year): ?array
    {
        [$startUtc] = TimeConverter::kstDateToUtcRange($year . '-01-01');
        [, $endUtc] = TimeConverter::kstDateToUtcRange($year . '-12-31');
        $rows = $this->photos->findByUserBetween($userId, $startUtc, $endUtc);

        $points = [];
        $photoRows = [];
        foreach ($rows as $row) {
            $lat = $row['lat'] ?? null;
            $lng = $row['lng'] ?? null;
            if ($lat === null || $lng === null) {
                continue; // GPS 없는 사진은 클러스터링 대상에서 제외.
            }

            $points[] = ['lat' => (float) $lat, 'lng' => (float) $lng];
            $photoRows[] = $row;
        }

        if ($points === []) {
            return null;
        }

        $assignments = PointClusterer::assignClusters($points);
        $counts = array_count_values($assignments);
        arsort($counts);
        $topIndex = array_key_first($counts);

        $firstRow = null;
        $visitCount = 0;
        foreach ($assignments as $i => $clusterIndex) {
            if ($clusterIndex !== $topIndex) {
                continue;
            }
            $visitCount++;
            $firstRow ??= $photoRows[$i];
        }

        $thumbnailPath = (string) ($firstRow['thumbnail_path'] ?? '');

        return [
            'lat' => (float) $firstRow['lat'],
            'lng' => (float) $firstRow['lng'],
            'visit_count' => $visitCount,
            'thumbnail_url' => $thumbnailPath !== '' ? '/thumbnails/' . (int) $firstRow['id'] : null,
        ];
    }
}
```

- [ ] **Step 4: 테스트 통과 확인**

Run: `vendor/bin/phpunit tests/unit/TripYearlyStatsServiceTest.php`
Expected: `OK (6 tests, ...)`

- [ ] **Step 5: Services.php 에 팩토리 등록**

`app/Config/Services.php`의 `use` 목록(30-32번째 줄)에 다음 줄을 `use App\Services\TripSummaryService;`
바로 아래에 추가:

```php
use App\Services\TripYearlyStatsService;
```

`tripStats()` 메서드(130-137번째 줄) 바로 뒤에 다음 메서드를 추가:

```php
    /**
     * 연간 통계 서비스 — 총 여행일수·캘린더 히트맵·가장 많이 방문한 지점.
     */
    public static function tripYearlyStats(bool $getShared = true): TripYearlyStatsService
    {
        if ($getShared) {
            return static::getSharedInstance('tripYearlyStats');
        }

        return new TripYearlyStatsService(new TripModel(), new PhotoLocationModel());
    }
```

(`TripModel`, `PhotoLocationModel`은 이미 파일 상단에 `use`돼 있으므로 추가 import는 필요 없다.)

- [ ] **Step 6: 정적 분석 통과 확인**

Run: `vendor/bin/phpstan analyse --ansi --memory-limit=1G`
Expected: `[OK] No errors`

- [ ] **Step 7: 커밋**

```bash
git add app/Services/TripYearlyStatsService.php app/Config/Services.php tests/unit/TripYearlyStatsServiceTest.php
git commit -m "$(cat <<'EOF'
✨ feat: 연간 여행일수·최다 방문 지점 TripYearlyStatsService 추가 (#29)

EOF
)"
```

---

### Task 3: TripController::stats() + 라우트

**Files:**
- Modify: `app/Controllers/TripController.php:27-44`(`index()` 메서드 다음에 `stats()` 추가)
- Modify: `app/Config/Routes.php:42`(`trips/data` 다음 줄에 라우트 추가)
- Test: `tests/feature/TripControllerTest.php`(파일 끝에 테스트 추가)

**Interfaces:**
- Consumes: `App\Services\TripYearlyStatsService::buildForYear(int, int): array{travel_days: int, heatmap_dates: list<string>, top_spot: array{...}|null}`
  (Task 2, `service('tripYearlyStats')`로 접근).
- Produces: `GET /trips/stats` JSON 응답 `{year: int, travel_days: int, heatmap_dates: list<string>, top_spot: array{lat: float, lng: float, visit_count: int, thumbnail_url: string|null}|null}`.

- [ ] **Step 1: 실패하는 테스트 작성**

`tests/feature/TripControllerTest.php` 파일 끝(마지막 테스트 메서드 다음, 클래스 닫는 중괄호
직전)에 다음 테스트를 추가한다:

```php
    public function testStatsRequiresLogin(): void
    {
        $result = $this->get('trips/stats');

        $result->assertStatus(401);
    }

    public function testStatsReturnsThisYearsTravelDaysAndTopSpot(): void
    {
        $year = (int) date('Y');

        (new PhotoLocationModel())->saveMany($this->userId, [
            new PhotoLocation('ys1', 37.5665, 126.9780, $year . '-06-01 01:00:00'),
        ]);
        (new TripModel())->insert([
            'user_id' => $this->userId, 'title' => 'T', 'body' => '',
            'start_date' => $year . '-06-01', 'end_date' => $year . '-06-03', 'cover_photo_id' => null,
        ]);

        $result = $this->withSession(['user_id' => $this->userId])->get('trips/stats');

        $result->assertStatus(200);
        $data = json_decode($result->getJSON() ?? '', true);
        $this->assertSame($year, $data['year']);
        $this->assertSame(3, $data['travel_days']);
        $this->assertSame([$year . '-06-01', $year . '-06-02', $year . '-06-03'], $data['heatmap_dates']);
        $this->assertNotNull($data['top_spot']);
        $this->assertSame(1, $data['top_spot']['visit_count']);
    }
```

(파일 상단에 `PhotoLocationModel`, `PhotoLocation`, `TripModel`이 이미 `use`돼 있는지 확인하고,
없으면 기존 테스트에서 쓰는 것과 동일한 `use` 문을 추가한다 — 기존 `testShowDataReturnsTripAndDaySummaries`
류 테스트가 이미 이 클래스들을 쓰고 있으므로 대부분 이미 import돼 있다.)

- [ ] **Step 2: 테스트가 실패하는지 확인**

Run: `vendor/bin/phpunit --filter "testStatsRequiresLogin|testStatsReturnsThisYearsTravelDaysAndTopSpot" tests/feature/TripControllerTest.php`
Expected: FAIL — `trips/stats` 라우트가 없어 404(또는 컨트롤러 메서드 없음 에러)

- [ ] **Step 3: TripController::stats() 메서드 추가**

`app/Controllers/TripController.php`의 `index()` 메서드(27-44번째 줄) 바로 다음에 다음 메서드를
추가한다:

```php
    /**
     * 올해(KST) 여행 통계 — 총 여행일수·캘린더 히트맵·가장 많이 방문한 지점(JSON, GET /trips/stats).
     */
    public function stats(): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => '로그인이 필요합니다.']);
        }

        $year = (int) substr(TimeConverter::utcToKst(date('Y-m-d H:i:s')), 0, 4);
        $stats = service('tripYearlyStats')->buildForYear($userId, $year);

        return $this->response->setJSON([
            'year' => $year,
            'travel_days' => $stats['travel_days'],
            'heatmap_dates' => $stats['heatmap_dates'],
            'top_spot' => $stats['top_spot'],
        ]);
    }
```

(`TimeConverter`는 이미 파일 상단에 `use App\Support\TimeConverter;`로 import돼 있다.)

`app/Config/Routes.php`의 42번째 줄(`$routes->get('trips/data', 'TripController::data');`)
바로 다음에 다음 줄을 추가한다:

```php
$routes->get('trips/stats', 'TripController::stats'); // (:num) 라우트보다 먼저 선언
```

- [ ] **Step 4: 테스트 통과 확인**

Run: `vendor/bin/phpunit tests/feature/TripControllerTest.php`
Expected: `OK (28 tests, ...)` — 기존 26개 + 신규 2개.

- [ ] **Step 5: 정적 분석 통과 확인**

Run: `vendor/bin/phpstan analyse --ansi --memory-limit=1G`
Expected: `[OK] No errors`

- [ ] **Step 6: 커밋**

```bash
git add app/Controllers/TripController.php app/Config/Routes.php tests/feature/TripControllerTest.php
git commit -m "$(cat <<'EOF'
✨ feat: 여행 연간 통계 API 엔드포인트 추가 (#29)

EOF
)"
```

---

### Task 4: trips.php UI — 잔디 그리드 + 요약 + 브라우저 실측 검증

**Files:**
- Modify: `app/Views/trips.php`(HTML/CSS/JS)

**Interfaces:**
- Consumes: `GET /trips/stats` JSON 응답(Task 3).

- [ ] **Step 1: HTML 섹션 추가**

`app/Views/trips.php`의 55-58번째 줄(`<main ...>` 시작부터 `<h2 class="section-title">저장된
여행</h2>` 앞)을 다음으로 교체한다:

```html
    <main data-trips-url="<?= esc($tripsUrl, 'attr') ?>">
        <h1 class="page-title">내 여행</h1>
        <p class="page-lead">연속된 날짜를 하나의 여행으로 묶어 커버 사진·기간·사진 수를 한눈에 봅니다.</p>

        <section class="yearly-stats" id="yearly-stats" hidden>
            <h2 class="section-title" id="yearly-stats-title"></h2>
            <p class="yearly-summary" id="yearly-summary"></p>
            <div class="top-spot-card" id="top-spot-card" hidden>
                <img class="top-spot-thumb" id="top-spot-thumb" alt="" hidden>
                <span id="top-spot-label"></span>
            </div>
            <div class="heatmap-grid" id="heatmap-grid"></div>
        </section>

        <h2 class="section-title">저장된 여행</h2>
```

- [ ] **Step 2: CSS 추가**

`<style>` 블록의 `.empty { color: #777; font-size: 13px; padding: 12px 0; }` 줄(50번째 줄)
바로 다음에 다음 규칙을 추가한다:

```css
        .yearly-summary { font-size: 14px; color: #444; margin: 0 0 12px; }
        .top-spot-card { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
        .top-spot-thumb { width: 48px; height: 48px; object-fit: cover; border-radius: 8px; display: block; }
        .heatmap-grid {
            display: grid; grid-template-rows: repeat(7, 12px); grid-auto-columns: 12px; grid-auto-flow: column;
            gap: 3px; overflow-x: auto; padding-bottom: 4px;
        }
        .heatmap-cell { width: 12px; height: 12px; border-radius: 2px; background: #ebedf0; }
        .heatmap-cell.active { background: #1a73e8; }
```

- [ ] **Step 3: JS 렌더링 로직 추가**

`app/Views/trips.php`의 `<script>` 블록 안, `fetch(tripsUrl + '/data', ...)` 호출(77-83번째
줄) 바로 다음에 다음 코드를 추가한다:

```javascript
            fetch(tripsUrl + '/stats', { headers: { Accept: 'application/json' } })
                .then(function (res) { return res.json(); })
                .then(renderYearlyStats)
                .catch(function () { /* 통계 로딩 실패는 조용히 무시 — 여행 목록은 별개로 동작 */ });

            function renderYearlyStats(stats) {
                var sectionEl = document.getElementById('yearly-stats');
                var titleEl = document.getElementById('yearly-stats-title');
                var summaryEl = document.getElementById('yearly-summary');
                var topSpotCardEl = document.getElementById('top-spot-card');
                var topSpotThumbEl = document.getElementById('top-spot-thumb');
                var topSpotLabelEl = document.getElementById('top-spot-label');
                var gridEl = document.getElementById('heatmap-grid');

                sectionEl.hidden = false;
                titleEl.textContent = stats.year + '년 여행 기록';
                summaryEl.textContent = '총 여행일수 ' + stats.travel_days + '일';

                if (stats.top_spot) {
                    topSpotCardEl.hidden = false;
                    if (stats.top_spot.thumbnail_url) {
                        topSpotThumbEl.src = stats.top_spot.thumbnail_url;
                        topSpotThumbEl.hidden = false;
                    } else {
                        topSpotThumbEl.hidden = true;
                    }
                    topSpotLabelEl.textContent = '가장 많이 방문한 곳 · ' + stats.top_spot.visit_count + '번 방문';
                } else {
                    topSpotCardEl.hidden = true;
                }

                renderHeatmap(gridEl, stats.year, stats.heatmap_dates);
            }

            function renderHeatmap(gridEl, year, activeDates) {
                gridEl.innerHTML = '';
                var activeSet = {};
                activeDates.forEach(function (d) { activeSet[d] = true; });

                var start = new Date(year, 0, 1);
                var end = new Date(year, 11, 31);

                // 1월 1일이 속한 주의 일요일부터 그리드를 시작한다(GitHub 스타일 정렬).
                var gridStart = new Date(start);
                gridStart.setDate(gridStart.getDate() - gridStart.getDay());

                var cursor = new Date(gridStart);
                while (cursor <= end) {
                    var cell = document.createElement('div');
                    cell.className = 'heatmap-cell';

                    if (cursor >= start && cursor <= end) {
                        var dateStr = formatDate(cursor);
                        if (activeSet[dateStr]) {
                            cell.classList.add('active');
                            cell.title = dateStr;
                        }
                    }

                    gridEl.appendChild(cell);
                    cursor.setDate(cursor.getDate() + 1);
                }
            }

            function formatDate(d) {
                var mm = String(d.getMonth() + 1).padStart(2, '0');
                var dd = String(d.getDate()).padStart(2, '0');
                return d.getFullYear() + '-' + mm + '-' + dd;
            }
```

- [ ] **Step 4: composer ci 전체 검증**

Run 순서(하나씩 실행 — `composer ci`는 CS Fixer 단계가 느려 타임아웃될 수 있으므로 개별 실행
권장):
```bash
vendor/bin/php-cs-fixer fix --dry-run --diff --ansi
vendor/bin/phpstan analyse --ansi --memory-limit=1G
vendor/bin/phpunit --no-coverage
```
Expected: CS Fixer `Found 0 of ... files that can be fixed`, PHPStan `[OK] No errors`, PHPUnit
`OK (284 tests, ...)`(기존 273개 + Task 1 신규 3개 + Task 2 신규 6개 + Task 3 신규 2개 = 284개).

- [ ] **Step 5: 브라우저 실측 검증**

임시 SQLite 환경을 띄운다(기존 세션에서 반복 사용한 패턴 — `.env`를 백업 후 Python으로 원본
`app.baseURL`·DB 설정 줄을 in-place 치환, 완료 후 반드시 원복):

```bash
cp .env .env.bak-yearly-heatmap-verify
python3 - <<'EOF'
p = '.env'
s = open(p).read()
s = s.replace("app.baseURL = 'http://localhost:8080/'", "app.baseURL = 'http://localhost:8299/'")
open(p, 'w').write(s)
EOF
cat >> .env <<'EOF'

# TEMP yearly-heatmap-verify
database.default.database = dev-yearly-heatmap-verify.db
database.default.DBDriver = SQLite3
database.default.DBPrefix =
EOF
php spark migrate --all
```

임시 spark 커맨드(`app/Commands/SeedYearlyHeatmapVerify.php`, 검증 후 삭제)로 사용자 1명 +
올해(`date('Y')`) 서로 다른 두 시기에 걸친 여행 2건(예: 6월 1~3일, 9월 10~11일)과 각 여행
기간의 사진들(한 지점에 여러 장 몰아 "최다 방문 지점"이 뚜렷하게 나오도록)을 시딩한다. 서버를
`php -S localhost:8299 -t public`로 띄우고 세션 파일에 PHP 네이티브 포맷(`user_id|i:1;`)으로
`user_id`를 주입한 뒤, `/trips` 페이지에 접속한다.

다음을 확인한다:
1. "N년 여행 기록" 제목과 "총 여행일수 N일" 요약이 시딩한 값과 정확히 일치하는지.
2. "가장 많이 방문한 곳" 카드에 대표 썸네일과 방문 횟수가 표시되는지.
3. 잔디 그리드에서 시딩한 두 여행 기간의 날짜 칸만 진한 색으로 표시되고, 나머지는 연한 회색인지
   (스크린샷으로 확인).
4. `read_network_requests`로 `GET /trips/stats` 요청이 정상 200으로 나가는지 확인.

검증이 끝나면 임시 커맨드와 환경을 원복한다:

```bash
rm -f app/Commands/SeedYearlyHeatmapVerify.php
mv .env.bak-yearly-heatmap-verify .env
rm -f writable/dev-yearly-heatmap-verify.db
rm -f writable/session/ci_session*
git status --short
```

`git status --short` 결과가 이번 작업 커밋 대상 파일 외에 다른 변경을 포함하지 않는지 확인한다.

- [ ] **Step 6: 커밋**

```bash
git add app/Views/trips.php
git commit -m "$(cat <<'EOF'
✨ feat: 내 여행 페이지에 연간 통계·캘린더 히트맵 UI 추가 (#29)

EOF
)"
```
