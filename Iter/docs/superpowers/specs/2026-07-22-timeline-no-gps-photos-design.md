# GPS 없는 사진도 시간표에 노출 — 설계

## 배경

이슈 [#29](https://github.com/pushwing/varius/issues/29)(라벨 `Iter`): 현재 업로드 파이프라인은
GPS 좌표가 없는 사진을 파싱 단계에서 완전히 버린다. 촬영 시각은 있는데 위치 정보만 없는
사진(예: 실내에서 GPS 신호를 못 잡은 경우)도 그날의 시간표(`GET /timeline/{date}`)에서는
빠짐없이 보여야 한다는 요청이다.

원 이슈에는 "연간 통계/캘린더 히트맵"이라는 두 번째 제안도 함께 담겨 있었으나, 서로 독립적인
기능이라 이번 스펙은 **GPS 없는 사진 노출** 하나만 다룬다. 나머지 하나는 별도 이슈/스펙으로
분리한다.

## 현재 동작 (문제)

```
TakeoutMetadataParser::parse() / PhotoExifParser::parse()
  → 좌표가 없으면(또는 (0,0)이면) 전체를 null 로 반환
  → TakeoutIngestService/PlainZipIngestService::extractCandidates() 가 $parsed === null 이면 스킵
  → 촬영 시각이 멀쩡히 있어도 사진 자체가 후보에서 완전히 사라짐
```

## 범위

- **포함**: 좌표 없이 촬영 시각만 있는 사진을 업로드 파이프라인에서 살려 저장하고, 시간표
  페이지에 노출한다. 이상치 필터·여행 통계(이동거리·방문 지점)가 이 사진들 때문에 깨지지
  않도록 방어한다.
- **제외**: 지도 페이지(`/map`)는 이번 변경과 무관하게 기존 그대로 유지한다(좌표 없는 사진은
  지도에 표시 불가능하므로 완전히 제외). "연간 통계/캘린더 히트맵"(이슈 #29의 두 번째 제안)은
  다루지 않는다.
- DB 스키마 변경 없음 — `photo_locations.lat`/`lng` 컬럼은 이미 `nullable`이다.

## 설계

### 1. 업로드 파이프라인 — nullable 좌표 관통

- `App\Services\Ingest\ExifLocation`, `App\Services\Ingest\PhotoLocation`: 생성자 프로퍼티
  `lat`/`lng` 타입을 `float` → `?float`로 변경한다.
- `TakeoutMetadataParser::parse()`: 좌표가 없거나 `(0,0)`이어도(현재처럼 위치 없음으로 판정),
  촬영 시각이 있으면 `new ExifLocation(null, null, $takenAt)`을 반환한다(현재는 좌표 없으면
  메서드 전체가 `null`을 반환해 시각 파싱 결과까지 버려진다).
- `PhotoExifParser::parse()`: 동일하게 GPS 없이도 `DateTimeOriginal`/`DateTime`이 있으면
  `new ExifLocation(null, null, $takenAt)`을 반환한다.
- `TakeoutIngestService::extractCandidates()`, `PlainZipIngestService::extractCandidates()`:
  candidate 채택 조건을 `$parsed !== null && $parsed->takenAt !== null`(좌표 유무 무관)로
  바꾼다. `new PhotoLocation($sourceItemId, $parsed->lat, $parsed->lng, $parsed->takenAt)`
  호출 자체는 `lat`/`lng`가 nullable이 되므로 그대로 통과한다.
- `AbstractZipIngestService::filterOutliers()`(속도 기반 이상치 필터): 좌표 없는 지점은
  속도 계산이 성립하지 않으므로 **항상 `$kept`에 포함**하고, "직전 유효 지점(`$previous`)"
  갱신에는 관여하지 않는다 — 좌표 없는 사진을 몇 장 건너뛰어도 그다음 좌표 있는 사진은 그
  이전의 **마지막 좌표 있는 지점**을 기준으로 이상치 판정을 받는다. `$dropped`(직전에 걸러진
  지점) 복권 로직도 좌표 있는 지점끼리만 비교한다.

### 2. 지도 페이지 — 완전 제외 (변경 최소화)

`RouteVisualizationService::buildForUser()`가 `PhotoLocationModel::findByUserOrdered()`로
받은 행을 순회할 때, `lat`이나 `lng`가 `null`인 행은 그룹핑 루프 진입 전에 건너뛴다. 이
메서드는 지도 전용으로만 쓰이므로(다른 서비스가 공유하지 않음) 서비스 내부에서 스킵하는 것으로
충분하고, 모델 메서드 자체나 SQL 조건은 건드리지 않는다(다른 컨슈머에 영향 없음을 보장).

### 3. 시간표(`TimelineService`) — 직전 세그먼트 포함

`segmentByPlace()`에서 좌표가 `null`인 사진은:

- 직전에 열린 세그먼트가 있으면(좌표 있는 세그먼트든, 이미 "위치 없음" 세그먼트든) 그
  세그먼트의 `photos`에 그대로 추가한다 — "그 장소에서 이어진 촬영"으로 취급.
- 직전 세그먼트가 없으면(하루의 첫 사진부터 좌표가 없는 경우) `lat: null, lng: null`인 새
  세그먼트를 연다.
- 좌표 있는 사진이 다시 나오면 `GeoDistanceCalculator::kilometers()`로 거리 비교가 필요한데,
  직전 세그먼트의 `lat`/`lng`가 `null`이면 "같은 장소"로 볼 수 없으므로 무조건 새 세그먼트를
  연다(좌표 있는 세그먼트 시작).
- `TimelineService::buildForDate()`의 반환 타입 `slots[].lat`/`lng`는 이미 `float|null`로
  선언돼 있어(기존 PHPDoc 그대로) 타입 변경이 필요 없다.
- 프론트(`app/Views/trip-detail.php`, `app/Views/map.php`의 시간표 레이어 공통 로직)는
  `slot.lat`/`slot.lng`가 둘 다 `null`이면 주변 업장(POI) 조회 요청을 보내지 않고, 그 자리에
  "위치 정보 없음" 텍스트를 표시한다(기존에도 `if (slot.lat !== null && slot.lng !== null)`
  조건으로 POI 조회 여부를 가르고 있어 —변경 없이 이미 안전하게 동작한다).

### 4. 여행 통계(`TripStatsService`) — 좌표 없는 사진 제외

`TripStatsService::buildStatsFromRows()`가 `$rows`를 좌표 배열로 변환하는 시점에 `lat`이나
`lng`가 `null`인 행은 건너뛴다(현재 `(float) ($row['lat'] ?? 0)`처럼 `0`으로 캐스팅하던 부분을
`null` 체크로 먼저 걸러내도록 변경). 이동거리 누적과 `PointClusterer::countClusters()` 둘 다
좌표 있는 사진만 대상으로 계산된다. `TripSummaryService::buildDaySummariesFromRows()`(날짜별
사진 수·썸네일)는 애초에 좌표를 쓰지 않으므로 변경이 필요 없다 — 좌표 없는 사진도 그대로
`photo_count`에 포함되고 썸네일 후보가 될 수 있다.

## 데이터 흐름 예시

```
사진 A(09:00, 좌표 있음) → 사진 B(09:05, GPS 없음) → 사진 C(09:10, GPS 없음)
  → 사진 D(09:30, 좌표 있음, A와 같은 장소)
  → 사진 E(10:00, 좌표 있음, 다른 장소)

시간표 세그먼트:
  09:00 세그먼트(좌표=A) — 사진 A, B, C (B·C는 직전 세그먼트에 편입)
  09:30 세그먼트(좌표=D) — 사진 D (좌표 있어 A와의 거리 비교로 "같은 장소" 판정되면 09:00
                            세그먼트에 편입, 다르면 새 세그먼트 — 기존 세그먼트 판정 로직 그대로)
  10:00 세그먼트(좌표=E) — 사진 E
```

## 엣지 케이스

| 상황 | 처리 |
|------|------|
| 하루의 모든 사진이 GPS 없음 | 세그먼트 하나(`lat: null, lng: null`)에 전부 편입, POI 조회 없음. `photo_count`는 정상 집계. |
| 좌표 있는 사진 없이 좌표 없는 사진만 있는 여행 | `TripStatsService`가 `distance_km: 0.0, spot_count: 0` 반환(기존 "사진 0장" 케이스와 동일 계산 경로). |
| 이상치 필터가 좌표 없는 사진 앞뒤로 좌표 있는 사진의 속도를 검사 | 좌표 없는 사진을 건너뛰고 마지막 좌표 있는 지점 기준으로 비교(위 "1. 업로드 파이프라인" 참고). |
| 커버 사진 자동 선택(`TripSummaryService::resolveCoverId`) | 좌표를 쓰지 않는 로직(`firstThumbnailBetween`, 썸네일 유무만 확인)이라 이번 변경과 무관하게 그대로 동작 — 좌표 없는 사진도 썸네일만 있으면 커버 후보가 된다. |

## 테스트 전략 (TDD)

기존 프로젝트 패턴(순수 파서/서비스는 Mock 없는 유닛 테스트, DB 접근 서비스는
`createMock(PhotoLocationModel::class)`)을 그대로 따른다.

- `tests/unit/Ingest/TakeoutMetadataParserTest.php`, `PhotoExifParserTest.php`: 좌표 없이
  촬영 시각만 있는 입력 → `ExifLocation(null, null, $takenAt)` 반환 확인(기존: 좌표 없으면
  `null` 반환 케이스가 있다면 그 테스트는 이 요구사항에 맞게 수정).
- `tests/unit/AbstractZipIngestServiceTest.php`(또는 하위 클래스 테스트): 좌표 없는
  `PhotoLocation`이 섞인 목록에서 `filterOutliers()`가 그 지점을 항상 유지하고, 좌표 있는
  다음 지점의 이상치 판정이 마지막 좌표 있는 지점 기준으로 정상 동작하는지 확인.
- `tests/unit/TimelineServiceTest.php`: 좌표 없는 사진이 직전 세그먼트에 편입되는 케이스,
  하루의 첫 사진부터 좌표 없는 케이스(새 `lat: null` 세그먼트) 둘 다 커버.
- `tests/unit/TripStatsServiceTest.php`: rows에 좌표 없는 행이 섞였을 때 `distance_km`/
  `spot_count` 계산에서 제외되는지 확인.
- 브라우저 실측 검증: 좌표 없는 사진이 섞인 날짜를 시딩해 시간표 페이지에서 "위치 정보 없음"
  세그먼트가 정상 렌더링되고 POI 조회 요청이 나가지 않는지 확인.
