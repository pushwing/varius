# Iter — Google Photos GPS 동선 시각화

Google Takeout에서 내보낸 사진 zip을 업로드하면 `.json` 사이드카에서 촬영 시간과
GPS 좌표를 추출해 지도에 표시하고, **날짜별 이동 동선**(마커 + 경로선)으로
시각화하는 서비스입니다. 프로젝트명 *Iter*는 라틴어로 여정·경로를 뜻합니다.

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

## 구성

- **CI4 백엔드**
  - `GooglePhotosAuthService` — OAuth2 로그인/콜백/토큰 발급·갱신(사용자 식별 전용, `openid`·`email`·`profile` 스코프만 사용 — Photos API는 더 호출하지 않음)
  - `TakeoutIngestService` + `app/Services/Ingest/*` — zip 압축 해제(`NativeUploadedZipHandler`), 사이드카 파싱(`TakeoutMetadataParser`), 200장 상한·이상치 필터, 썸네일 생성(`GdThumbnailGenerator`)
  - `RouteVisualizationService` — 날짜별 동선 조합·지도 응답 데이터 생성
  - `GeoDistanceCalculator` — 하버사인 거리 계산(이상치 필터에 사용)
- **DB** — MySQL (`users`, `oauth_tokens`, `photo_locations`) — 원본 이미지는 저장하지 않고 좌표·시간·참조 파일명·썸네일 경로만 보관
- **프론트엔드** — Leaflet.js + OpenStreetMap
  - 날짜별 색상 구분 마커·경로선, 같은 장소 사진은 마커 하나로 묶어 갤러리 팝업(개수/더보기)으로 표시
  - 좌측 사이드바에 월별/일별 동선 목록 트리 — 월 토글, 일자 클릭 시 지도 이동 + 해당 레이어 오픈 + 마커 팝업 동시 표시
  - 로그인 후 공통 상단 내비게이션 바(홈/지도 페이지 공유)

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
php spark migrate           # users / oauth_tokens / photo_locations 마이그레이션
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
불가능해 실제 브라우저로 수동 확인합니다. 그 외 사이드카 파싱·이상치 필터링·OAuth 흐름·동선 조합
로직은 `tests/unit`·`tests/feature`·`tests/database`가 커버합니다.
