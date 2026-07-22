# 여행 상세 — 이동거리·방문 지점 통계 설계

## 배경

`docs/superpowers/specs/2026-07-22-trip-grouping-design.md`의 "열린 질문" 항목: "이동거리·방문
지역 수 같은 부가 통계는 이번엔 제외했지만, `GeoDistanceCalculator`를 그대로 재사용할 수 있어
추후 추가가 어렵지 않다."

이 설계는 그 후속 과제를 구현한다 — 여행(날짜 범위) 상세 페이지에 그 여행 동안의 **총 이동거리**와
**방문 지점 수**를 배지로 보여준다.

## 범위

- **포함**: 여행 상세 페이지(`/trips/{id}`) 제목 바로 아래에 "총 이동거리 · 방문 지점 수" 배지
  표시. 기존 `GeoDistanceCalculator`(직선거리 계산) 재사용. 기존 `RouteVisualizationService`의
  "가까운 지점 클러스터링"(30m 반경) 로직을 `PointClusterer`라는 공용 클래스로 추출해 재사용.
- **제외**: "내 여행" 목록 페이지(`trips.php`)의 카드에는 표시하지 않는다(상세 페이지 전용).
  공개 공유 페이지(`trip-share.php`)에도 노출하지 않는다(Trip Grouping 스펙에서 이미 "요약(날짜
  +사진 그리드)만"으로 범위를 제한한 결정을 그대로 유지). 실제 행정구역 기반 역지오코딩(예: "종로구
  방문")은 다루지 않는다 — "방문 지점 수"는 좌표 클러스터 개수로 정의한다. 날짜별 세부 통계(날짜
  간 이동거리 등)는 다루지 않는다 — 여행 전체 합산 값만 보여준다.
- 새 HTTP 엔드포인트나 DB 마이그레이션은 없다 — 기존 `GET /trips/{id}/data` 응답에 필드를
  추가하는 순수 백엔드+프론트엔드 변경이다.

## 통계 정의

- **총 이동거리(`distance_km`)**: 여행 기간(KST) 내 사진을 촬영 시각(`taken_at`) 오름차순으로
  정렬한 뒤, 인접한 좌표쌍마다 `GeoDistanceCalculator::kilometers()`로 계산한 직선거리를 모두
  더한 값. 여행 전체 누적값 하나만 계산하며(날짜별로 나누지 않음), 사진이 0~1장이면 `0.0`.
- **방문 지점 수(`spot_count`)**: 같은 기간의 좌표를 `PointClusterer`(30m 반경, `RouteVisualization
  Service`와 동일 기준)로 클러스터링한 결과 클러스터 개수. 사진이 0장이면 `0`, 1장이면 `1`.

## 아키텍처 · 컴포넌트

**신규: `app/Services/PointClusterer.php`**

`RouteVisualizationService::clusterByProximity()`(현재 private, 지도용 사진 메타데이터까지 묶어
반환)에서 "가까운 지점끼리 묶기" 판정 로직만 좌표 전용 정적 유틸리티로 추출한다.

```php
final class PointClusterer
{
    private const CLUSTER_RADIUS_KM = 0.03; // RouteVisualizationService 에서 이전, 값 불변

    /**
     * @param list<array{lat: float, lng: float}> $points
     */
    public static function countClusters(array $points): int
}
```

**신규: `app/Services/TripStatsService.php`**

여행(날짜 범위) 단위 통계 전담 서비스. `TripSummaryService`와 같은 계층에 위치하고 같은 방식으로
`PhotoLocationModel`을 주입받는다.

```php
final class TripStatsService
{
    public function __construct(private readonly PhotoLocationModel $photos) {}

    /**
     * @return array{distance_km: float, spot_count: int}
     */
    public function buildStats(int $userId, string $startDate, string $endDate): array
}
```

내부에서 `PhotoLocationModel::findByUserBetween()`(기존 메서드, `taken_at` 오름차순 보장)으로
좌표를 가져와 `GeoDistanceCalculator::kilometers()`로 누적 거리를, `PointClusterer::countClusters()`
로 지점 수를 계산한다. `TripSummaryService`와 동일하게 `TimeConverter::kstDateToUtcRange()`로
KST 날짜를 UTC 범위로 변환해 조회한다.

**수정: `app/Services/RouteVisualizationService.php`**

`clusterByProximity()`가 내부적으로 `PointClusterer`의 판정 로직을 사용하도록 리팩터링한다(중복
제거). 반환 형태(사진 메타데이터가 포함된 클러스터 배열)와 기존 동작·기존 테스트는 변경하지 않는다.

**수정: `app/Config/Services.php`**

기존 `tripSummary()`/`tripSuggestion()` 팩토리와 동일한 패턴으로 `tripStats()`를 등록한다.

**수정: `app/Controllers/TripController.php`**

`showData()`가 응답 JSON에 `stats` 필드를 추가한다.

**수정: `app/Views/trip-detail.php`**

제목(`<h1>`) 바로 아래에 배지를 렌더링한다.

## 데이터 흐름

```
GET /trips/{id}/data (기존 엔드포인트, 새 라우트 없음)
  → TripController::showData()
    → service('tripStats')->buildStats($userId, $startDate, $endDate)
      → PhotoLocationModel::findByUserBetween() — taken_at 오름차순 좌표 조회
      → 인접 좌표쌍마다 GeoDistanceCalculator::kilometers() 누적 → distance_km
      → PointClusterer::countClusters() → spot_count
  → JSON 응답에 "stats": { "distance_km": 12.3, "spot_count": 5 } 추가
  → trip-detail.php 의 기존 fetch 콜백이 stats 필드를 읽어 배지 렌더링
```

응답 예시:

```json
{
  "trip": { "...": "..." },
  "days": [ "..." ],
  "stats": { "distance_km": 12.3, "spot_count": 5 }
}
```

## 엣지 케이스

| 상황 | 처리 |
|------|------|
| 사진이 0장인 여행 | `distance_km: 0.0`, `spot_count: 0`. |
| 사진이 1장뿐인 여행 | `distance_km: 0.0`(더할 쌍이 없음), `spot_count: 1`. |
| 모든 사진이 반경 30m 이내(같은 장소) | `distance_km`는 GPS 오차만큼 작은 값, `spot_count: 1`. |
| `lat`/`lng` 비정상값 | 별도 검증 없이 그대로 계산에 사용 — 업로드 단계(`TakeoutIngestService`의 이상치 필터)에서 이미 걸러졌다고 신뢰(`RouteVisualizationService`와 동일한 기존 전제를 그대로 따름). |

## 표시 형식

배지 텍스트: `"총 {distance_km}km · 방문 지점 {spot_count}곳"`. `distance_km`는 소수점 1자리로
반올림해 표시(예: `12.3km`). `0.0km`·`0곳`인 경우도 숨기지 않고 그대로 표시한다.

## 테스트 전략 (TDD)

기존 프로젝트 패턴(`createMock(PhotoLocationModel::class)`로 Model을 목킹하는 순수 유닛 테스트)을
그대로 따른다.

**`tests/unit/PointClustererTest.php`** (신규)
- 좌표 0개 → `0`
- 좌표 1개 → `1`
- 반경(30m) 이내 좌표 2개 → `1`로 묶임
- 반경 밖 좌표 2개(서울시청·부산시청) → `2`
- 반경 경계값(정확히 0.03km) 처리 확인

**`tests/unit/TripStatsServiceTest.php`** (신규)
- 좌표 0개 → `{distance_km: 0.0, spot_count: 0}`
- 좌표 1개 → `{distance_km: 0.0, spot_count: 1}`
- 알려진 좌표 3점(서로 충분히 떨어진 지점) → 예상 누적거리와 `assertEqualsWithDelta`로 비교,
  `spot_count: 3`
- Model이 반환하는 순서(`taken_at` 오름차순)를 서비스가 재정렬하지 않고 그대로 신뢰하는지 확인

**`tests/unit/RouteVisualizationServiceTest.php`** (기존, 회귀 확인)
- `PointClusterer` 추출 후에도 기존 클러스터링 테스트가 그대로 통과하는지만 재실행 확인 — 동작
  변경이 없으므로 신규 테스트는 추가하지 않는다.

**`tests/feature/TripControllerTest.php`** (기존 파일에 케이스 추가)
- `GET /trips/{id}/data` 응답 JSON에 `stats.distance_km`, `stats.spot_count` 키가 포함되는지 확인

**브라우저 실측 검증**
- 사진 2장 이상 시딩된 여행 상세 페이지에서 배지 텍스트가 제목 아래 정상 렌더링되는지 확인
- `composer ci` 전체(PHPStan 레벨 6 + PHPUnit) 통과 확인
