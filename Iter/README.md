# Iter — Google Photos GPS 동선 시각화

Google Takeout에서 내보낸 사진 zip을 업로드하면 `.json` 사이드카에서 촬영 시간과
GPS 좌표를 추출해 지도에 표시하고, **날짜별 이동 동선**(마커 + 경로선)으로
시각화하는 서비스입니다. 여기서 한 걸음 더 나아가 하루의 **시간표**(장소·주변 업장·메모)를
살펴보거나, 여러 날을 하나의 **여행**으로 묶어 이동거리·방문 지점 통계와 함께 관리·공유할 수도
있습니다. 프로젝트명 *Iter*는 라틴어로 여정·경로를 뜻합니다.

## 동작 흐름

```
[사용자] → Google OAuth2 로그인(신원 확인용) → Takeout zip 업로드(POST /takeout/upload)
   → 압축 해제 → .json 사이드카(geoData/geoDataExif) 파싱 → 촬영 시각·좌표 매칭
   → 200장 상한 적용 → 이상치 필터(직전 지점 대비 시속 200km 초과 제외)
   → 좌표 추출 성공 시 300px 썸네일 생성 → 원본·업로드 zip·임시 디렉터리 즉시 삭제
   → DB 저장(좌표/시간/참조 파일명/썸네일 경로) → 지도 시각화(GET /map)
```

> **왜 Takeout zip 업로드인가?** Google Photos Picker API·Library API는 원본 다운로드 시
> **GPS EXIF를 의도적으로 제거합니다**(Google 공식 문서 기준). 위치 정보를 원본 그대로 얻는
> 유일한 경로는 사용자가 직접 요청하는 **Google Takeout**뿐이며, Takeout도 사진 파일 자체의
> GPS는 지우지만 함께 내려주는 `.json` 사이드카의 `geoData`/`geoDataExif`에는 보존합니다.
> 자세한 배경은 [`CLAUDE.md`](CLAUDE.md)의 "핵심 전제"를 참고하세요.

## 주요 기능

### 지도 시각화 (`GET /map`)

- Leaflet.js + OpenStreetMap 위에 날짜별 색상 구분 마커·경로선을 표시하고, 같은 장소에서
  연속 촬영한 사진은 마커 하나로 묶어 갤러리 팝업(개수/더보기)으로 보여줍니다.
- 좌측 사이드바에 월별/일별 동선 목록 트리 — 월 토글, 일자 클릭 시 지도 이동 + 해당 레이어
  오픈 + 마커 팝업 동시 표시. 사진 확대 뷰어(좌우 이동·회전·삭제)도 지원합니다.
- 사진 업로드 완료 직후 지도로 이동하면 방금 올린 사진의 월·날짜가 자동으로 선택됩니다.

### 날짜별 시간표 (`GET /timeline/{date}`)

- 하루치 좌표를 "장소가 바뀌는 시점" 기준의 세그먼트로 묶어, 시각·사진·주변 업장(Overpass
  API 기반 카페·식당 등)·메모를 한 화면에서 볼 수 있습니다.
- 날짜 단위 제목/메모(`day_notes`)와 세그먼트 단위 메모(`time_notes`)를 각각 인라인으로
  저장할 수 있고, 시간표 전체를 SNS로 공유하는 공개 링크도 만들 수 있습니다.
- 사진 로딩(썸네일)을 주변 업장 조회보다 먼저 마치도록 순차 처리해 응답이 밀리지 않게
  합니다.

### 여행(Trip) 관리 (`GET /trips`)

- 촬영 날짜 사이 공백이 3일 이상이면 자동으로 "여행" 후보(기간·사진 수·제목)를 제안하고,
  사용자가 승인하면 저장됩니다. 저장된 여행은 제목·설명·기간·커버 사진을 수정할 수 있습니다.
- 여행 상세 페이지(`GET /trips/{id}`)에서 포함된 날짜별로 시간표를 인라인으로 펼쳐볼 수
  있고(지도 페이지로 이동할 필요 없음), 제목 아래에 그 여행의 **총 이동거리**와 **방문
  지점 수**(30m 반경 클러스터 기준)를 배지로 보여줍니다.
- 여행 단위로 SNS 공유 링크(`GET /t/{token}`)를 만들 수 있으며, 공개 페이지는 로그인 없이
  날짜별 사진 그리드 요약만 노출합니다(시간표 세부 정보·통계는 제외).

## 구성

- **CI4 백엔드**
  - `GooglePhotosAuthService` — OAuth2 로그인/콜백/토큰 발급·갱신(사용자 식별 전용, `openid`·`email`·`profile` 스코프만 사용 — Photos API는 더 호출하지 않음)
  - `TakeoutIngestService` + `app/Services/Ingest/*` — zip 압축 해제(`NativeUploadedZipHandler`), 사이드카 파싱(`TakeoutMetadataParser`), 200장 상한·이상치 필터, 썸네일 생성(`GdThumbnailGenerator`)
  - `RouteVisualizationService` — 날짜별 동선 조합·지도 응답 데이터 생성
  - `GeoDistanceCalculator` — 하버사인 거리 계산(이상치 필터·이동거리 통계에 공용 사용)
  - `PointClusterer` — 좌표를 30m 반경으로 클러스터링(같은 장소 묶기·방문 지점 수 계산에 공용 사용)
  - `PhotoManagementService` — 개별 사진 썸네일 회전·삭제
  - `TimelineService` + `Poi\OverpassPoiLookup` — 하루 좌표를 장소 세그먼트로 그룹핑, 날짜·세그먼트 메모 병합, 주변 업장 조회(좌표당 캐싱)
  - `TripSummaryService` / `TripSuggestionService` / `TripStatsService` — 여행 날짜별 사진 요약·커버 결정 / 3일 공백 규칙 자동 제안 / 이동거리·방문 지점 통계 계산
- **DB** — MySQL
  - `users`, `oauth_tokens`, `photo_locations` — 원본 이미지는 저장하지 않고 좌표·시간·참조 파일명·썸네일 경로만 보관
  - `day_notes`, `time_notes` — 날짜·시간대별 메모, `share_links` — 시간표 공개 공유 토큰
  - `trips`, `trip_share_links` — 여행(날짜 범위) 그룹, 여행 단위 공개 공유 토큰
- **프론트엔드** — Leaflet.js + OpenStreetMap
  - 로그인 후 공통 상단 내비게이션 바(지도·업로드·내 여행 페이지 공유)

## 제약

- 업로드 zip 은 요청당 최대 **500MB**, 추출 좌표는 사용자당 **200장** 상한(동기 처리, 큐 인프라 없음).
- 업로드는 사용자당(미로그인 시 IP당) 시간당 기본 **10회**로 레이트 리밋(`ratelimit.session.per_hour`, `SessionRateLimitFilter`).
- 원본 이미지·업로드 zip·압축 해제용 임시 디렉터리는 처리 완료(성공·실패 무관) 즉시 삭제 — 좌표 추출에 성공한 지점의 300px 썸네일만 예외적으로 보관.

상세 명세는 [`docs/photo-gps-tracker-spec.md`](docs/photo-gps-tracker-spec.md)를(단, GPS 획득 방식은
Takeout zip 업로드로 대체됨), Claude Code 작업 규칙은 [`CLAUDE.md`](CLAUDE.md)를 참고하세요.

## 로컬 실행 · 설정

빠른 시작은 [`docs/SETUP.md`](docs/SETUP.md)를 참고하세요. 요약:

```bash
composer install
cp env .env
php spark key:generate      # encryption.key 설정 (토큰 암호화에 필수)
php spark migrate           # users / oauth_tokens / photo_locations / day_notes / time_notes / trips 등 전체 마이그레이션
php spark serve
```

`.env`에 MySQL 접속 정보와 Google OAuth 자격증명(클라이언트 ID/시크릿/리디렉션 URI)을 채운 뒤
`/auth/google`로 접속하면 로그인 → `/map`에서 zip 업로드 화면으로 이어집니다. 세부 절차·트러블슈팅은
[`docs/SETUP.md`](docs/SETUP.md)에 정리되어 있습니다.

## 로컬 검증

```bash
composer ci      # CS Fixer → PHPStan → PHPUnit 순차 실행(머지 전 필수)
```

zip 업로드의 "성공 경로"(실제 파일 이동)는 PHP `is_uploaded_file()` 제약으로 자동화 테스트가
불가능해 실제 브라우저로 수동 확인합니다. 그 외 사이드카 파싱·이상치 필터링·OAuth 흐름·동선 조합·
시간표 그룹핑·여행 자동 제안·이동거리 통계 로직은 `tests/unit`·`tests/feature`·`tests/database`가
커버합니다.
