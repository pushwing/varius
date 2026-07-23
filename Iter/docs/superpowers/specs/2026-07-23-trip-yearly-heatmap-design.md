# 연간 통계·캘린더 히트맵 설계

## 배경

이슈 [#29](https://github.com/pushwing/varius/issues/29)(라벨 `Iter`)의 두 번째 제안: "연간 통계/캘린더
히트맵 — '올해 며칠 여행했는지', '가장 많이 간 도시' 같은 대시보드. 구현이 가볍고(기존 데이터 집계만)
앱을 열 이유를 하나 더 만들어줍니다." 첫 번째 제안(GPS 없는 사진 시간표 노출)은 이미 별도로
구현·배포됐다.

## 범위

- **포함**: "내 여행" 페이지(`/trips`)에 올해의 총 여행일수, GitHub 잔디 스타일 캘린더 히트맵,
  가장 많이 방문한 지점(대표 사진 + 방문 횟수)을 보여주는 새 섹션을 추가한다.
- **제외**: 실제 도시/지역명 표시(역지오코딩 인프라 부재 — 아래 "가장 많이 방문한 지점" 절 참고).
  연도 선택/전환 UI(올해만 고정 표시). 월별 캘린더 형태의 히트맵.

## 핵심 결정사항

### "가장 많이 간 도시" → "가장 많이 방문한 지점"으로 범위 조정

프로젝트에는 좌표를 실제 지명으로 바꾸는 역지오코딩 인프라가 전혀 없다(좌표만 저장). 이슈 자체가
"구현이 가볍고(기존 데이터 집계만)"라고 전제했으므로, 새 외부 API 연동 대신 이미 있는
`PointClusterer`(30m 반경 좌표 클러스터링, `RouteVisualizationService`·`TripStatsService`가 이미
사용 중)를 재사용해 "그 해에 가장 많이 방문한 좌표 지점"(대표 사진 1장 + 방문 사진 수)을
보여준다.

### 여행일수 = 저장된 여행(Trip)의 날짜 범위만

"여행"이라는 단어의 의미를 정확히 지키기 위해, 사진이 찍힌 모든 날이 아니라 사용자가 명시적으로
저장한 `trips` 레코드의 `[start_date, end_date]` 범위만 합산한다. 아직 여행으로 묶어 저장하지
않은 사진(자동 제안만 되고 저장 안 한 상태)은 포함하지 않는다.

### 히트맵 형태: GitHub 잔디 스타일(연중 가로 그리드), 올해만 고정 표시

53주 × 7일 그리드로 1년 전체를 한눈에 보여준다. 월별 미니 캘린더 12개보다 구현이 단순하고
정보 밀도가 높다. 연도 전환 UI는 없음 — 항상 "올해"(KST 기준)만 보여준다.

## 아키텍처

### `app/Models/TripModel.php` — 신규 메서드

```php
findByUserInYear(int $userId, int $year): array
```
그 해(KST)와 겹치는 여행을 `WHERE start_date <= '{year}-12-31' AND end_date >= '{year}-01-01'`
조건으로 조회한다.

### `app/Services/TripYearlyStatsService.php` (신규)

```php
buildForYear(int $userId, int $year): array{
    travel_days: int,
    heatmap_dates: list<string>,
    top_spot: array{lat: float, lng: float, visit_count: int, thumbnail_url: string|null}|null,
}
```

- **여행일수·히트맵 날짜**: `TripModel::findByUserInYear()`로 가져온 여행들의 날짜 범위를 그 해로
  잘라낸(clamp) 뒤 문자열 집합(Set)에 모은다 — 연도를 걸치는 여행(예: 12/30~1/2)도 그 해에
  속하는 날짜만 포함된다. 저장 시점에 `TripModel::overlaps()`가 겹치는 여행을 막고 있지만,
  집합 사용으로 중복 계산을 한 번 더 방지한다. `travel_days`는 이 집합의 크기, `heatmap_dates`는
  정렬된 `YYYY-MM-DD` 배열이다.
- **가장 많이 방문한 지점**: `PhotoLocationModel::findByUserBetween()`을 그 해 KST 1/1~12/31에
  대응하는 UTC 범위로 호출해 좌표를 가져오고(좌표 없는 사진은 제외 — 이슈 #29 1번 작업과
  일관된 처리), `PointClusterer::assignClusters()`로 클러스터링한다. 사진 수가 가장 많은
  클러스터의 좌표·사진 수·대표 썸네일(그 클러스터의 첫 사진)을 반환한다. 좌표 있는 사진이
  하나도 없으면 `top_spot: null`.

### `app/Controllers/TripController.php` — 신규 `stats()` 메서드

로그인 체크 후 KST 기준 "올해" 연도(`substr(TimeConverter::utcToKst(date('Y-m-d H:i:s')), 0, 4)`)로
`service('tripYearlyStats')->buildForYear($userId, $year)`를 호출해 JSON으로 반환한다.

### `app/Config/Routes.php`

```php
$routes->get('trips/stats', 'TripController::stats');
```
기존 `trips/data`와 같은 이유로 `trips/(:num)` 라우트보다 먼저 선언한다.

### `app/Config/Services.php`

기존 `tripSummary()`/`tripStats()` 팩토리와 동일한 패턴으로 `tripYearlyStats()`를 등록한다.

## UI (`app/Views/trips.php`)

페이지 리드 문구와 "저장된 여행" 섹션 사이에 새 `<section>`을 추가한다:
- "총 여행일수 N일" 요약 텍스트
- "가장 많이 방문한 곳" 카드(대표 썸네일 이미지 + "N번 방문") — `top_spot`이 `null`이면 이 카드는
  숨긴다(사진이 아직 없는 신규 사용자 대응)
- 그 아래 잔디 그리드: 순수 CSS Grid(외부 차트 라이브러리 없음), 53열 × 7행, 1월 1일의 요일에
  맞춰 첫 주 시작 위치를 정렬하고, `heatmap_dates`에 포함된 날짜만 진한 색, 나머지는 연한 회색
  셀로 표시한다.

`fetch(tripsUrl + '/stats')`로 페이지 로드 시 기존 `fetch(tripsUrl + '/data')`와 별도로 호출한다
(관심사 분리 — 여행 목록 로딩 실패가 통계 표시를 막지 않고, 그 반대도 마찬가지).

## 엣지 케이스

| 상황 | 처리 |
|------|------|
| 올해 저장된 여행이 하나도 없음 | `travel_days: 0`, `heatmap_dates: []` — 그리드는 전부 연한 회색으로 렌더링(에러 아님) |
| 올해 좌표 있는 사진이 하나도 없음 | `top_spot: null` — "가장 많이 방문한 곳" 카드 숨김 |
| 여행이 연도를 걸침(예: 2025-12-30~2026-01-02) | 2026년 조회 시 2026-01-01, 2026-01-02만 `heatmap_dates`에 포함 |
| 겹치는 여행(정상적으로는 저장 시 막힘) | 날짜 집합(Set) 사용으로 중복 카운트 없이 안전하게 처리 |

## 테스트 전략 (TDD)

- `tests/database/TripModelTest.php`: `findByUserInYear()` — 연도 안에 완전히 포함되는 여행,
  연도를 걸치는 여행(포함/미포함 경계), 다른 연도 여행(제외) 케이스.
- `tests/unit/TripYearlyStatsServiceTest.php`(신규, Mock 기반): 겹치지 않는 여행 여러 개의
  날짜 합산, 연도를 걸치는 여행의 날짜 clamp, 좌표 클러스터 중 최다 방문 지점 선정, 사진이
  0장이거나 전부 좌표 없을 때 `top_spot: null`.
- `tests/feature/TripControllerTest.php`: `GET /trips/stats` e2e 테스트(로그인 필요, 정상
  응답 형태 확인).
- 브라우저 실측 검증: 서로 다른 지점 사진들을 여러 날짜·여행에 걸쳐 시딩해 총 여행일수·최다
  방문 지점 카드·잔디 그리드가 올바르게 렌더링되는지 확인.
