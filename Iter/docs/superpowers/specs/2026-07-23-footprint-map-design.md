# 여행 발자국 지도 (Footprint Map) 설계

- 날짜: 2026-07-23
- 대상: `Iter/` — 새 페이지 `/footprint` + 인제스트·스키마 확장
- 상태: 설계 승인됨

## 목적

사용자가 사진으로 남긴 좌표를 지역 단위로 집계해, 방문한 곳을 색칠한 "발자국 지도"를
보여준다. 연간 통계와 짝을 이루는 누적 성취감 기능이다.

## 요구사항 (확정)

| 항목 | 결정 |
|------|------|
| 지역 단위 | **국내 시·도(17개) + 해외 국가** — 한국은 시·도로 세분, 해외는 국가 단위 |
| 지역 판별 | **번들 GeoJSON + 오프라인 point-in-polygon** — 외부 API·레이트리밋 없음 |
| 저장 전략 | **업로드 시 판별해 컬럼 저장** + 기존 데이터는 spark 백필 커맨드 |
| 페이지 구성 | **단일 지도 + 통계 헤더** — 세계지도에서 한국만 시·도 폴리곤으로 세분 렌더 |

## 기각한 대안

- **Nominatim 역지오코딩 API**: 레이트리밋(1req/s)·외부 장애 의존·캐싱 필수 — 오프라인 판별로 회피.
- **요청 시마다 계산**: 사진 수천 장이면 매 요청 PIP 재계산이 느리고 캐시가 필요해짐.
- **별도 집계 테이블**: 사진 삭제 시 집계 갱신 누락 등 정합성 부담.
- **geoPHP 등 라이브러리 의존**: 필요한 건 ray-casting 하나 — 직접 구현이 더 가볍다.

## 상세 설계

### 데이터

- 마이그레이션: `photo_locations` 에 컬럼 추가
  - `country_code VARCHAR(2) NULL` — ISO 3166-1 alpha-2 (예: `KR`, `JP`)
  - `region_code VARCHAR(8) NULL` — 국내만, ISO 3166-2:KR (예: `KR-11` 서울)
  - 인덱스 `idx_photo_locations_user_country (user_id, country_code)`
- 경계 GeoJSON 은 `public/assets/geo/` 에 번들:
  - `world-countries.json` — Natural Earth 110m admin-0 (퍼블릭 도메인)
  - `kr-sido.json` — 시·도 17개 간략화 경계 (라이선스 확인 후 채택)
  - 기본 원칙은 백엔드 판별과 프론트 choropleth 렌더가 **같은 파일**을 사용하는 것.
    단, 110m 해안선이 판별에 너무 거칠면 백엔드 판별용으로만 50m 파일을 별도 번들할 수 있다
    (그 경우에도 프론트 렌더는 110m 유지 — 아래 유의점 참조).

### 지역 판별 — `RegionResolver` 서비스

- 입력 `(lat, lng)` → 출력 `{ countryCode: ?string, regionCode: ?string }`.
- 알고리즘: 각 폴리곤의 bbox 프리체크로 후보 축소 → ray casting point-in-polygon
  (MultiPolygon·구멍(holes) 지원).
- 한국(`KR`) 판정 시 시·도 GeoJSON 으로 2차 판별해 `region_code` 결정.
- 바다·경계 밖은 둘 다 null — 통계에서 제외.
- GeoJSON 로딩은 lazy + 인스턴스 캐시(요청당 1회 파싱).
- 순수 로직 — PHPUnit 단위 테스트 필수(서울→KR/KR-11, 도쿄→JP, 태평양→null, 경계 인근).

### 적재·백필

- 인제스트(`AbstractZipIngestService` 계열)에서 GPS 추출 성공 시 판별해 함께 INSERT.
  GPS 없는 사진은 null 유지.
- 기존 행은 `php spark region:backfill` 커맨드로 일괄 판별
  (`country_code IS NULL AND lat/lng NOT NULL` 대상, 배치 처리).

### API·페이지

- `GET /footprint` — 발자국 페이지(로그인 필요, 미로그인 시 OAuth 리다이렉트).
- `GET /footprint/data` — JSON(로그인 필요):
  ```json
  {
    "countries": [{ "code": "JP", "photos": 42 }],
    "regions":   [{ "code": "KR-11", "photos": 310 }],
    "stats":     { "countryCount": 3, "regionCount": 5 }
  }
  ```
  - `countries` 에는 KR 도 포함(국가 카운트용). `regions` 는 KR 시·도만.
  - `FootprintService` 가 GROUP BY 집계. 가벼운 SQL 이라 캐시 없음.
- 페이지 구성:
  - Leaflet 세계지도 + 번들 GeoJSON choropleth. 한국은 국가 폴리곤 대신 시·도 폴리곤 렌더.
  - 방문 지역 채색(단색), 미방문은 옅은 회색. 호버 툴팁: 지역명 · 사진 N장.
  - 상단 통계 카드: "방문 국가 N개국 · 국내 시·도 N/17".
  - `partials/nav` 에 "발자국" 링크 추가.

### 아키텍처 배치

| 구성 요소 | 위치 |
|-----------|------|
| `RegionResolver` | `app/Services/Region/RegionResolver.php` (+ 인터페이스 없이 단일 구현) |
| `FootprintService` | `app/Services/FootprintService.php` |
| `FootprintController` | `app/Controllers/FootprintController.php` (얇게 — 인증 확인·위임·응답) |
| 백필 커맨드 | `app/Commands/RegionBackfill.php` (`region:backfill`) |
| 뷰 | `app/Views/footprint.php` |

## 검증

- `RegionResolver`·`FootprintService` PHPUnit 단위 테스트 + `composer ci`.
- 페이지는 실제 브라우저 확인 — DB 미설정 로컬에서는 canned 데이터 dev 라우트로 렌더 검증
  (동선 재생 때와 동일한 방법, 검증 후 원복).
- 백필 커맨드는 로컬에서 dry-run 성 출력 확인.

## 유의점

- 시·도 경계 데이터는 **라이선스 확인 후** 번들한다(GADM 은 비상업 제한이라 회피,
  Natural Earth·공공데이터 계열 우선).
- 110m 해상도는 해안선이 거칠어 해안 사진이 바다로 판정될 수 있다 — 국가 판별은
  50m 해상도 사용을 우선 검토하되, 파일 크기(프론트 전송)와 균형을 본다.
  프론트 렌더와 백엔드 판별의 해상도를 다르게 가져가는 것(렌더 110m·판별 50m)도 허용.
