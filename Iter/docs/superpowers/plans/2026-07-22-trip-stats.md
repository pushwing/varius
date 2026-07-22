# 여행 상세 — 이동거리·방문 지점 통계 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 여행 상세 페이지(`/trips/{id}`) 제목 바로 아래에 그 여행의 총 이동거리(km)와 방문 지점
수(30m 반경 클러스터 개수)를 배지로 보여준다.

**Architecture:** 기존 `RouteVisualizationService`의 private 클러스터링 로직을 `PointClusterer`
공용 클래스로 추출해 재사용성을 확보한다. 신규 `TripStatsService`가 `PointClusterer`와 기존
`GeoDistanceCalculator`를 조합해 여행(날짜 범위) 단위 통계를 계산한다. `TripController::showData()`
가 이 서비스를 호출해 기존 JSON 응답에 `stats` 필드를 추가하고, `trip-detail.php`가 이를 읽어
배지를 렌더링한다. 새 HTTP 엔드포인트·DB 마이그레이션은 없다.

**Tech Stack:** PHP 8.4+ / CodeIgniter 4, PHPUnit 10.5(in-memory SQLite), PHPStan 레벨 6.

## Global Constraints

- `declare(strict_types=1)`, PSR-12, PHPStan 레벨 6 통과 필수.
- 새 클래스·메서드는 제네릭 타입(`list<array{...}>` 등) PHPDoc 명시 필수.
- TDD 순서 엄수: RED(실패 확인) → GREEN(최소 구현) → 커밋.
- 커밋 메시지: 이모지 + Conventional Commits 접두어 + 한국어 설명(예: `✨ feat: ...`).
- 클러스터 반경은 기존 `RouteVisualizationService::CLUSTER_RADIUS_KM`(0.03km = 30m)과 반드시
  동일해야 한다(지도 페이지의 "같은 장소" 판정과 여행 통계의 "방문 지점" 판정이 어긋나면 안 됨).
- 새 라우트·마이그레이션 없음 — 기존 `GET /trips/{id}/data` 응답만 확장한다.

---

### Task 1: PointClusterer 추출 + RouteVisualizationService 리팩터링

**Files:**
- Create: `app/Services/PointClusterer.php`
- Test: `tests/unit/PointClustererTest.php`
- Modify: `app/Services/RouteVisualizationService.php:23-129`(팔레트 상수는 그대로 두고
  `CLUSTER_RADIUS_KM` 상수와 `clusterByProximity()` 메서드만 변경)

**Interfaces:**
- Produces: `App\Services\PointClusterer::assignClusters(array $points): array` —
  `list<array{lat: float, lng: float}>` 을 받아 같은 길이의 `list<int>`(각 점이 속한 클러스터의
  0부터 시작하는 인덱스)를 반환한다. `App\Services\PointClusterer::countClusters(array $points): int`
  — `assignClusters()` 결과의 고유 인덱스 개수를 반환한다. Task 2·Task 3이 이 두 정적 메서드를
  그대로 사용한다.

- [ ] **Step 1: 실패하는 테스트 작성**

`tests/unit/PointClustererTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PointClusterer;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class PointClustererTest extends CIUnitTestCase
{
    public function testAssignClustersReturnsEmptyArrayForNoPoints(): void
    {
        $this->assertSame([], PointClusterer::assignClusters([]));
    }

    public function testCountClustersReturnsZeroForNoPoints(): void
    {
        $this->assertSame(0, PointClusterer::countClusters([]));
    }

    public function testSinglePointFormsOneCluster(): void
    {
        $points = [['lat' => 37.5665, 'lng' => 126.9780]];

        $this->assertSame([0], PointClusterer::assignClusters($points));
        $this->assertSame(1, PointClusterer::countClusters($points));
    }

    public function testNearbyPointsWithin30MetersMergeIntoOneCluster(): void
    {
        // 위도 0.0002도 차이 ≈ 22m — 같은 지점으로 묶여야 한다.
        $points = [
            ['lat' => 37.50000, 'lng' => 127.00000],
            ['lat' => 37.50020, 'lng' => 127.00000],
        ];

        $this->assertSame([0, 0], PointClusterer::assignClusters($points));
        $this->assertSame(1, PointClusterer::countClusters($points));
    }

    public function testFarApartPointsFormSeparateClusters(): void
    {
        // 위도 0.01도 차이 ≈ 1.1km — 같은 지점으로 보기엔 너무 멀다.
        $points = [
            ['lat' => 37.5000, 'lng' => 127.0000],
            ['lat' => 37.5100, 'lng' => 127.0000],
        ];

        $this->assertSame([0, 1], PointClusterer::assignClusters($points));
        $this->assertSame(2, PointClusterer::countClusters($points));
    }

    public function testThreePointsWhereFirstTwoAreCloseAndThirdIsFar(): void
    {
        $points = [
            ['lat' => 37.50000, 'lng' => 127.00000],
            ['lat' => 37.50020, 'lng' => 127.00000], // ≈22m — 첫 점과 같은 클러스터
            ['lat' => 37.6000, 'lng' => 127.1000],   // 멀리 떨어짐 — 새 클러스터
        ];

        $this->assertSame([0, 0, 1], PointClusterer::assignClusters($points));
        $this->assertSame(2, PointClusterer::countClusters($points));
    }
}
```

- [ ] **Step 2: 테스트가 실패하는지 확인**

Run: `vendor/bin/phpunit tests/unit/PointClustererTest.php`
Expected: FAIL — `Class "App\Services\PointClusterer" not found`

- [ ] **Step 3: 최소 구현 작성**

`app/Services/PointClusterer.php`(신규):

```php
<?php

declare(strict_types=1);

namespace App\Services;

/**
 * 좌표 목록을 가까운 지점끼리(GPS 오차 감안 반경) 묶는 순수 유틸리티.
 *
 * RouteVisualizationService(같은 장소 사진 묶기)와 TripStatsService(방문 지점 수 계산)가
 * 공용으로 사용한다 — 반경 기준이 두 곳에서 어긋나지 않도록 판정 로직을 한 곳에만 둔다.
 */
final class PointClusterer
{
    /** 이 거리(km) 이내 지점은 "같은 장소"로 묶는다(GPS 오차 감안, 약 30m). */
    private const CLUSTER_RADIUS_KM = 0.03;

    /**
     * 각 점이 속한 클러스터의 인덱스(0부터 시작)를 points 와 같은 길이의 배열로 반환한다.
     *
     * 클러스터 중심은 그 클러스터의 첫 지점 좌표로 고정한다(드리프트 방지) — 뒤에 들어오는
     * 점이 조금씩 어긋나며 클러스터가 실제 반경보다 넓게 퍼지는 것을 막기 위함이다.
     *
     * @param list<array{lat: float, lng: float}> $points
     *
     * @return list<int>
     */
    public static function assignClusters(array $points): array
    {
        /** @var list<array{lat: float, lng: float}> $centers */
        $centers = [];
        $assignments = [];

        foreach ($points as $point) {
            $matchedIndex = null;
            foreach ($centers as $index => $center) {
                $distanceKm = GeoDistanceCalculator::kilometers($center['lat'], $center['lng'], $point['lat'], $point['lng']);
                if ($distanceKm <= self::CLUSTER_RADIUS_KM) {
                    $matchedIndex = $index;
                    break;
                }
            }

            if ($matchedIndex === null) {
                $centers[] = ['lat' => $point['lat'], 'lng' => $point['lng']];
                $matchedIndex = count($centers) - 1;
            }

            $assignments[] = $matchedIndex;
        }

        return $assignments;
    }

    /**
     * @param list<array{lat: float, lng: float}> $points
     */
    public static function countClusters(array $points): int
    {
        return count(array_unique(self::assignClusters($points)));
    }
}
```

- [ ] **Step 4: 테스트 통과 확인**

Run: `vendor/bin/phpunit tests/unit/PointClustererTest.php`
Expected: `OK (6 tests, ...)`

- [ ] **Step 5: RouteVisualizationService 가 PointClusterer 를 사용하도록 리팩터링**

`app/Services/RouteVisualizationService.php`의 `CLUSTER_RADIUS_KM` 상수(28번 줄)를 삭제하고,
`clusterByProximity()`(97-129번 줄)를 다음으로 교체한다:

```php
    /**
     * GPS 오차를 감안해 가까운 지점끼리 묶는다(같은 장소 연속촬영). 각 클러스터의
     * 좌표는 그 클러스터의 첫 지점 좌표를 기준으로 삼는다(드리프트 방지).
     *
     * @param list<array{lat: float, lng: float, taken_at: string, media_item_id: string, thumbnail_url: string|null}> $points
     *
     * @return list<array{lat: float, lng: float, photos: list<array{media_item_id: string, taken_at: string, thumbnail_url: string|null}>}>
     */
    private function clusterByProximity(array $points): array
    {
        $assignments = PointClusterer::assignClusters(array_map(
            static fn (array $point): array => ['lat' => $point['lat'], 'lng' => $point['lng']],
            $points,
        ));

        /** @var array<int, array{lat: float, lng: float, photos: list<array{media_item_id: string, taken_at: string, thumbnail_url: string|null}>}> $clusters */
        $clusters = [];

        foreach ($points as $i => $point) {
            $clusterIndex = $assignments[$i];
            $photo = [
                'media_item_id' => $point['media_item_id'],
                'taken_at' => $point['taken_at'],
                'thumbnail_url' => $point['thumbnail_url'],
            ];

            if (! isset($clusters[$clusterIndex])) {
                $clusters[$clusterIndex] = [
                    'lat' => $point['lat'],
                    'lng' => $point['lng'],
                    'photos' => [],
                ];
            }
            $clusters[$clusterIndex]['photos'][] = $photo;
        }

        return array_values($clusters);
    }
```

파일 상단(6번 줄 부근) `use` 구문에 클러스터링 헬퍼가 같은 네임스페이스(`App\Services`)에
있으므로 별도 `use App\Services\PointClusterer;`는 필요 없다(같은 네임스페이스 클래스는
import 불필요).

- [ ] **Step 6: 기존 RouteVisualizationService 테스트로 회귀 확인**

Run: `vendor/bin/phpunit tests/unit/RouteVisualizationServiceTest.php`
Expected: `OK (11 tests, ...)` — 리팩터링 전과 동일하게 전부 통과(동작 변경 없음을 확인).

- [ ] **Step 7: 커밋**

```bash
git add app/Services/PointClusterer.php app/Services/RouteVisualizationService.php tests/unit/PointClustererTest.php
git commit -m "$(cat <<'EOF'
✨ feat: 좌표 클러스터링 로직을 PointClusterer 로 추출

EOF
)"
```

---

### Task 2: TripStatsService + Services.php 등록

**Files:**
- Create: `app/Services/TripStatsService.php`
- Test: `tests/unit/TripStatsServiceTest.php`
- Modify: `app/Config/Services.php`(팩토리 메서드 추가)

**Interfaces:**
- Consumes: `App\Services\PointClusterer::countClusters(array $points): int`(Task 1),
  `App\Services\GeoDistanceCalculator::kilometers(float, float, float, float): float`(기존),
  `App\Models\PhotoLocationModel::findByUserBetween(int $userId, string $startUtc, string $endUtc): array`
  (기존 — `id, source_item_id, lat, lng, thumbnail_path, taken_at` 컬럼을 `taken_at` 오름차순으로
  반환), `App\Support\TimeConverter::kstDateToUtcRange(string $date): array{0: string, 1: string}`(기존).
- Produces: `App\Services\TripStatsService::buildStats(int $userId, string $startDate, string $endDate): array{distance_km: float, spot_count: int}`.
  Task 3이 `service('tripStats')->buildStats(...)`로 호출한다.

- [ ] **Step 1: 실패하는 테스트 작성**

`tests/unit/TripStatsServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PhotoLocationModel;
use App\Services\GeoDistanceCalculator;
use App\Services\TripStatsService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TripStatsServiceTest extends CIUnitTestCase
{
    /**
     * @param list<array<string, mixed>> $photoRows
     */
    private function service(array $photoRows): TripStatsService
    {
        $model = $this->createMock(PhotoLocationModel::class);
        $model->method('findByUserBetween')->willReturn($photoRows);

        return new TripStatsService($model);
    }

    public function testReturnsZeroesWhenNoPhotos(): void
    {
        $stats = $this->service([])->buildStats(1, '2024-03-15', '2024-03-16');

        $this->assertSame(0.0, $stats['distance_km']);
        $this->assertSame(0, $stats['spot_count']);
    }

    public function testSinglePhotoHasZeroDistanceAndOneSpot(): void
    {
        $stats = $this->service([
            ['lat' => '37.5665', 'lng' => '126.9780', 'taken_at' => '2024-03-15 01:00:00'],
        ])->buildStats(1, '2024-03-15', '2024-03-16');

        $this->assertSame(0.0, $stats['distance_km']);
        $this->assertSame(1, $stats['spot_count']);
    }

    public function testAccumulatesDistanceInChronologicalOrderAndCountsSpots(): void
    {
        // 서울시청 → 강남역 → 잠실, 세 지점 모두 서로 30m 반경 밖(별개 지점).
        $p1 = ['lat' => 37.5665, 'lng' => 126.9780];
        $p2 = ['lat' => 37.4979, 'lng' => 127.0276];
        $p3 = ['lat' => 37.5133, 'lng' => 127.1000];

        $stats = $this->service([
            ['lat' => (string) $p1['lat'], 'lng' => (string) $p1['lng'], 'taken_at' => '2024-03-15 01:00:00'],
            ['lat' => (string) $p2['lat'], 'lng' => (string) $p2['lng'], 'taken_at' => '2024-03-15 02:00:00'],
            ['lat' => (string) $p3['lat'], 'lng' => (string) $p3['lng'], 'taken_at' => '2024-03-15 03:00:00'],
        ])->buildStats(1, '2024-03-15', '2024-03-16');

        $expectedDistance = GeoDistanceCalculator::kilometers($p1['lat'], $p1['lng'], $p2['lat'], $p2['lng'])
            + GeoDistanceCalculator::kilometers($p2['lat'], $p2['lng'], $p3['lat'], $p3['lng']);

        $this->assertEqualsWithDelta($expectedDistance, $stats['distance_km'], 0.0001);
        $this->assertSame(3, $stats['spot_count']);
    }

    public function testPhotosWithinClusterRadiusCountAsOneSpotWithNearZeroDistance(): void
    {
        // 두 지점 모두 같은 좌표(반경 이내) — 방문 지점은 1곳, 이동거리는 0에 가깝다.
        $stats = $this->service([
            ['lat' => '37.5665', 'lng' => '126.9780', 'taken_at' => '2024-03-15 01:00:00'],
            ['lat' => '37.5665', 'lng' => '126.9780', 'taken_at' => '2024-03-15 02:00:00'],
        ])->buildStats(1, '2024-03-15', '2024-03-16');

        $this->assertSame(0.0, $stats['distance_km']);
        $this->assertSame(1, $stats['spot_count']);
    }
}
```

- [ ] **Step 2: 테스트가 실패하는지 확인**

Run: `vendor/bin/phpunit tests/unit/TripStatsServiceTest.php`
Expected: FAIL — `Class "App\Services\TripStatsService" not found`

- [ ] **Step 3: 최소 구현 작성**

`app/Services/TripStatsService.php`(신규):

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
        $rows = $this->photos->findByUserBetween($userId, $startUtc, $endUtc);

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
Expected: `OK (4 tests, ...)`

- [ ] **Step 5: Services.php 에 팩토리 등록**

`app/Config/Services.php`의 `use` 목록(6-31번 줄)에 다음 줄을 `use App\Services\TripSuggestionService;`
바로 아래에 추가:

```php
use App\Services\TripStatsService;
```

`tripSuggestion()` 메서드(117-124번 줄) 바로 뒤에 다음 메서드를 추가:

```php
    /**
     * 여행(날짜 범위) 이동거리·방문 지점 수 통계 서비스.
     */
    public static function tripStats(bool $getShared = true): TripStatsService
    {
        if ($getShared) {
            return static::getSharedInstance('tripStats');
        }

        return new TripStatsService(new PhotoLocationModel());
    }
```

- [ ] **Step 6: 정적 분석 통과 확인**

Run: `composer analyse`
Expected: `[OK] No errors`

- [ ] **Step 7: 커밋**

```bash
git add app/Services/TripStatsService.php app/Config/Services.php tests/unit/TripStatsServiceTest.php
git commit -m "$(cat <<'EOF'
✨ feat: 여행 이동거리·방문 지점 통계 TripStatsService 추가

EOF
)"
```

---

### Task 3: TripController 응답 확장 + trip-detail.php 배지 표시 + 실측 검증

**Files:**
- Modify: `app/Controllers/TripController.php:173-215`(`showData()` 메서드)
- Modify: `tests/feature/TripControllerTest.php:215-233`(`testShowDataReturnsTripAndDaySummaries` 근처)
- Modify: `app/Views/trip-detail.php`(HTML/CSS/JS)

**Interfaces:**
- Consumes: `App\Services\TripStatsService::buildStats(int, string, string): array{distance_km: float, spot_count: int}`
  (Task 2, `service('tripStats')`로 접근).
- Produces: `GET /trips/{id}/data` JSON 응답에 `stats: {distance_km: float, spot_count: int}` 필드.
  `distance_km`는 컨트롤러에서 `round(..., 1)` 적용된 값(표시용, 프론트는 추가 반올림 불필요).

- [ ] **Step 1: 실패하는 feature 테스트 작성**

`tests/feature/TripControllerTest.php`의 `testShowDataReturnsTripAndDaySummaries()`(215-233번 줄)
끝부분(`$this->assertSame('2024-03-15', $data['days'][0]['date']);` 바로 다음, 233번 줄 `}` 앞)에
다음 두 줄을 추가한다(기존 시딩 데이터가 같은 좌표 `37.5, 127.0`의 서로 다른 날짜 사진 2장이므로
이동거리는 0, 방문 지점은 1곳이 된다):

```php
        $this->assertSame(0.0, $data['stats']['distance_km']);
        $this->assertSame(1, $data['stats']['spot_count']);
```

- [ ] **Step 2: 테스트가 실패하는지 확인**

Run: `vendor/bin/phpunit --filter testShowDataReturnsTripAndDaySummaries tests/feature/TripControllerTest.php`
Expected: FAIL — `Undefined array key "stats"`

- [ ] **Step 3: TripController::showData() 에 stats 필드 추가**

`app/Controllers/TripController.php`의 `showData()` 메서드(173-215번 줄) 중, `$days` 배열을 만드는
`foreach` 블록(192-201번 줄) 바로 다음, `return $this->response->setJSON([...]);`(203번 줄) 앞에
다음 코드를 추가:

```php
        $stats = service('tripStats')->buildStats($userId, $startDate, $endDate);
```

그리고 `return $this->response->setJSON([...])`의 배열에 `'days' => $days,`(213번 줄) 다음 줄로
`'stats'` 키를 추가한다:

```php
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
```

- [ ] **Step 4: 테스트 통과 확인**

Run: `vendor/bin/phpunit --filter testShowDataReturnsTripAndDaySummaries tests/feature/TripControllerTest.php`
Expected: `OK (1 test, ...)`

- [ ] **Step 5: trip-detail.php 에 배지 UI 추가 — HTML**

`app/Views/trip-detail.php`의 `.header-row` div(116-130번 줄) 바로 다음(131번 줄, `<div class="field-group">`
시작 전)에 배지 컨테이너를 추가한다:

```html
        <div class="trip-stats" id="trip-stats"></div>
```

- [ ] **Step 6: trip-detail.php 에 배지 UI 추가 — CSS**

`<style>` 블록의 `.header-actions { ... }`(29번 줄) 바로 다음에 다음 규칙을 추가:

```css
        .trip-stats { font-size: 13px; color: #555; margin: -10px 0 18px; }
```

- [ ] **Step 7: trip-detail.php 에 배지 UI 추가 — JS**

`render(data)` 함수(185-196번 줄)를 다음으로 교체한다(기존 6줄에 `renderTripStats(data.stats);`
한 줄만 추가):

```javascript
            function render(data) {
                var trip = data.trip;
                headingEl.textContent = trip.title;
                titleEl.value = trip.title;
                bodyEl.value = trip.body;
                startEl.value = trip.start_date;
                endEl.value = trip.end_date;
                selectedCoverId = trip.cover_photo_id;

                renderTripStats(data.stats);
                renderCoverPicker(data.days);
                renderDayList(data.days);
            }

            function renderTripStats(stats) {
                var statsEl = document.getElementById('trip-stats');
                statsEl.textContent = '총 ' + stats.distance_km.toFixed(1) + 'km · 방문 지점 ' + stats.spot_count + '곳';
            }
```

`renderTripStats` 함수 정의는 `render` 함수 바로 다음(197번 줄, 기존 `renderCoverPicker` 함수
정의 앞)에 위치시킨다.

- [ ] **Step 8: composer ci 전체 검증**

Run: `composer ci`
Expected: CS Fixer 통과, `[OK] No errors`(PHPStan), `OK (256 tests, ...)`(PHPUnit — 기존 246개 +
`PointClustererTest` 6개 + `TripStatsServiceTest` 4개 = 256개. `testShowDataReturnsTripAndDaySummaries`
에는 신규 테스트가 아니라 기존 테스트에 assertion 2개만 추가했으므로 테스트 개수엔 반영되지 않고
assertion 총합만 늘어난다).

- [ ] **Step 9: 브라우저 실측 검증**

임시 SQLite 환경을 띄운다(기존 세션에서 반복 사용한 패턴 — `.env`를 백업 후 Python으로 원본
`app.baseURL`·DB 설정 줄을 in-place 치환, 완료 후 반드시 원복):

```bash
cp .env .env.bak-trip-stats-verify
python3 - <<'EOF'
p = '.env'
s = open(p).read()
s = s.replace("app.baseURL = 'http://localhost:8080/'", "app.baseURL = 'http://localhost:8299/'")
open(p, 'w').write(s)
EOF
cat >> .env <<'EOF'

# TEMP trip-stats-verify
database.default.database = dev-trip-stats-verify.db
database.default.DBDriver = SQLite3
database.default.DBPrefix =
EOF
php spark migrate --all
```

시딩 스크립트로 사용자 1명 + 서로 30m 이상 떨어진 좌표 3장(서울시청·강남역·잠실, 같은 날짜)을
저장하고 여행을 생성한 뒤, `mcp__Claude_Browser__preview_start`로 `php -S localhost:8299 -t public`
서버를 띄우고 세션에 `user_id`를 주입해 `/trips/{id}` 페이지에 접속한다. 다음을 확인한다:

1. `read_page` 또는 스크린샷으로 제목(`<h1>`) 바로 아래에 `"총 X.Xkm · 방문 지점 3곳"` 형태의
   배지가 렌더링되는지 확인(구체적인 X.X 값은 세 좌표 간 실제 하버사인 거리 합).
2. 사진이 0장인 여행(별도로 하나 더 생성)에서는 `"총 0.0km · 방문 지점 0곳"`이 정상 표시되고
   에러 없이 렌더링되는지 확인.

검증이 끝나면 환경을 원복한다:

```bash
mv .env.bak-trip-stats-verify .env
rm -f writable/dev-trip-stats-verify.db
rm -f writable/session/ci_session*
git status --short
```

`git status --short` 결과가 이번 작업 커밋 대상 파일 외에 다른 변경을 포함하지 않는지 확인한다.

- [ ] **Step 10: 커밋**

```bash
git add app/Controllers/TripController.php app/Views/trip-detail.php tests/feature/TripControllerTest.php
git commit -m "$(cat <<'EOF'
✨ feat: 여행 상세에 이동거리·방문 지점 통계 배지 추가

EOF
)"
```
