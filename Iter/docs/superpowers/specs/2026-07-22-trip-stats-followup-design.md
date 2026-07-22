# 여행 상세 통계 — 후속 개선(중복 쿼리 제거 + 논제로 거리 테스트) 설계

## 배경

`docs/superpowers/plans/2026-07-22-trip-stats.md`(이동거리·방문 지점 통계) 병합 시 최종
전체 브랜치 리뷰(2026-07-22)에서 나온 Minor 항목 2건을 후속 작업으로 처리한다.

1. `TripController::showData()`가 `TripSummaryService::buildDaySummaries()`와
   `TripStatsService::buildStats()`를 모두 호출하는데, 둘 다 동일한
   `(userId, startDate, endDate)`로 `PhotoLocationModel::findByUserBetween()`을 각각
   호출해 요청당 사진 조회 쿼리가 중복 발생한다(200장 상한 내에서는 성능 영향이 미미하지만
   리소스 낭비이며, 프로젝트의 "N+1 금지" API 가이드에 해당하는 패턴이다).
2. `tests/feature/TripControllerTest.php`에 `stats.distance_km`이 0이 아닌 경우를
   검증하는 HTTP 레벨(feature) 테스트가 없다 — 현재 유일한 관련 테스트는 동일 좌표 사진
   2장을 시딩해 `distance_km = 0.0`인 경로만 커버한다. 누적거리 계산 자체는
   `TripStatsServiceTest`(유닛)가 이미 검증하지만, 컨트롤러가 그 값을 정확히 JSON에
   실어 보내는지는 e2e로 한 번도 확인되지 않았다.

## 범위

- **포함**: `TripStatsService`·`TripSummaryService`에 "이미 조회된 rows를 받는" 순수
  계산 메서드를 신설하고, `TripController::showData()`가 사진을 한 번만 조회해 양쪽에
  전달하도록 변경. `TripControllerTest.php`에 논제로 거리 e2e 테스트 1건 추가.
- **제외**: 서비스 통합(하나의 서비스로 합치기), 캐싱 레이어 도입, `TripShareController`나
  `TripController::data()`(목록 페이지의 커버 사진 결정 경로) 변경 — 이 두 호출부는 각각
  1회만 조회하므로 애초에 중복 문제가 없고, 기존 공개 시그니처를 그대로 사용해 영향받지
  않는다.

## 아키텍처 · 변경 파일

### `app/Services/TripStatsService.php`

```php
/**
 * 이미 조회된 좌표 행(taken_at 오름차순)으로 이동거리·방문 지점 수를 계산하는 순수 로직.
 * buildStats() 의 실제 계산부이며, TripController::showData() 처럼 사진 조회를 이미
 * 한 번 수행한 호출측이 중복 조회 없이 재사용하기 위해 공개 메서드로 노출한다.
 *
 * @param list<array<string, mixed>> $rows PhotoLocationModel::findByUserBetween() 과 같은
 *                                          형태(id, source_item_id, lat, lng, thumbnail_path, taken_at),
 *                                          taken_at 오름차순 정렬이 보장돼야 한다.
 *
 * @return array{distance_km: float, spot_count: int}
 */
public function buildStatsFromRows(array $rows): array
```

기존 `buildStats(int $userId, string $startDate, string $endDate): array`는 다음으로 축소한다:

```php
public function buildStats(int $userId, string $startDate, string $endDate): array
{
    [$startUtc] = TimeConverter::kstDateToUtcRange($startDate);
    [, $endUtc] = TimeConverter::kstDateToUtcRange($endDate);

    return $this->buildStatsFromRows($this->photos->findByUserBetween($userId, $startUtc, $endUtc));
}
```

### `app/Services/TripSummaryService.php`

같은 패턴으로 `buildDaySummariesFromRows(array $rows): array`를 신설하고, 기존
`buildDaySummaries(int $userId, string $startDate, string $endDate): array`를 조회 후
위임하는 래퍼로 축소한다. `resolveCoverId()`는 이 변경과 무관하므로 손대지 않는다.

### `app/Controllers/TripController.php::showData()`

`$summaryService->buildDaySummaries($userId, $startDate, $endDate)`를 호출하던 자리를
다음으로 교체한다(컨트롤러가 조회를 한 번만 수행):

```php
[$startUtc] = TimeConverter::kstDateToUtcRange($startDate);
[, $endUtc] = TimeConverter::kstDateToUtcRange($endDate);
$rows = model(PhotoLocationModel::class)->findByUserBetween($userId, $startUtc, $endUtc);

$days = [];
foreach ($summaryService->buildDaySummariesFromRows($rows) as $summary) { /* 기존과 동일 */ }

$stats = service('tripStats')->buildStatsFromRows($rows);
```

`resolveCoverId()` 호출(커버 사진 결정)은 이 변경과 무관하므로 그대로 둔다 — 별도의 작은
조회(`firstThumbnailBetween`)를 쓰거나 저장된 값을 그대로 신뢰하는 경로라 `findByUserBetween`
과 중복되지 않는다.

### 무변경 파일

- `app/Controllers/TripShareController.php` — 기존 `buildDaySummaries(userId, ...)` 공개
  시그니처를 그대로 사용, 영향 없음.
- `app/Controllers/TripController.php::data()`(여행 목록 JSON) — `resolveCoverId()`만
  호출하고 `buildDaySummaries()`/`buildStats()`는 애초에 쓰지 않으므로 무관.

## 데이터 흐름

```
GET /trips/{id}/data
  → TripController::showData()
    → $rows = PhotoLocationModel::findByUserBetween($userId, $startUtc, $endUtc)  // 쿼리 1회
    → TripSummaryService::buildDaySummariesFromRows($rows)  // 순수 계산, 추가 쿼리 없음
    → TripStatsService::buildStatsFromRows($rows)           // 순수 계산, 추가 쿼리 없음
```

## 테스트 전략 (TDD)

**`tests/unit/TripStatsServiceTest.php`** — 기존 4개 테스트(공개 API `buildStats()` 대상)는
그대로 유지해 회귀를 방지한다. `buildStatsFromRows()`에 대한 신규 테스트를 추가한다: 기존
테스트와 동일한 시나리오(빈 배열, 단일 좌표, 3점 누적거리, 클러스터 내 좌표)를 rows 배열을
직접 넘겨 검증 — Mock이 필요 없는 순수 함수 테스트다.

**`tests/unit/TripSummaryServiceTest.php`** — 동일한 방식으로 `buildDaySummariesFromRows()`
신규 테스트를 추가하고 기존 테스트는 유지한다.

**`tests/feature/TripControllerTest.php`** — 기존 `testShowDataReturnsTripAndDaySummaries`
(동일 좌표 2장, `distance_km = 0.0` 경로 커버)는 그대로 두고, 다음 신규 테스트를 추가한다:

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

(`distance_km`도 whole-number float→JSON int 축약 이슈가 있을 수 있으므로 기존 트립스탯
테스트와 동일하게 `(float)` 캐스트를 적용한다 — 이번엔 `assertGreaterThan`이라 값이 0이
아니면 캐스트 여부와 무관하게 통과하지만, 일관성을 위해 캐스트를 유지한다.)

## 검증

- `composer ci`(CS Fixer → PHPStan 레벨 6 → PHPUnit) 통과.
- 신규/변경 테스트 전부 통과, 기존 256개 테스트 전원 회귀 없이 통과.
- 브라우저 실측 검증은 이번 변경이 순수 리팩터링(응답 JSON 형태 불변)이라 생략 가능 —
  다만 컨트롤러가 실제로 쿼리 1회만 수행하는지는 코드 리뷰로 확인한다(별도 프로파일링 도구
  없이 diff 검토로 충분).
