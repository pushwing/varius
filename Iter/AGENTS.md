# Iter (Google Photos GPS 동선 시각화) 프로젝트 가이드

> 저장소 공통 규칙은 [`../AGENTS.md`](../AGENTS.md)를 따른다. 이 문서는 `Iter/`의 스택,
> 아키텍처, 데이터 처리, 검증 규칙만 다룬다.
>
> 전체 요구사항은 [`docs/photo-gps-tracker-spec.md`](docs/photo-gps-tracker-spec.md)를 참고한다.
> 단, GPS 획득 방식은 아래 핵심 전제에 따라 명세의 Google Photos 방식과 달리 Google Takeout zip 업로드를 사용한다.

## 프로젝트 개요와 핵심 전제

사용자가 Google Takeout에서 직접 내보낸 사진 zip을 업로드하면 `.json` 사이드카에서 GPS와 촬영 시각을
추출해 지도에 날짜별 이동 동선(마커 + 경로선)으로 표시하는 서비스다.

Google Photos Picker API와 Library API는 다운로드 원본에서 GPS EXIF를 제거한다. 반면 Google Takeout은
사진 파일의 GPS를 제거하지만 `.json` 사이드카의 `geoData` 또는 `geoDataExif`에 좌표를 보존한다. 따라서
이 프로젝트는 사용자가 직접 내보낸 Takeout zip을 업로드하는 방식으로 GPS를 획득한다.

## 기술 스택

- **백엔드**: PHP 8.2+ / CodeIgniter 4
- **인증**: Google OAuth2 — `openid`, `email`, `profile` 스코프. 사용자 식별용이며 Photos API는 호출하지 않는다.
- **GPS 획득**: `POST /takeout/upload` → `ZipArchive` 압축 해제 → `.json` 사이드카의 `geoData`/`geoDataExif` 파싱
- **DB**: MySQL
- **지도 시각화**: Leaflet.js + OpenStreetMap

## 서비스 경계

| 서비스 | 책임 |
|---|---|
| `GooglePhotosAuthService` | OAuth2 로그인/콜백/토큰 발급·갱신(사용자 식별용) |
| `TakeoutIngestService` | zip 압축 해제, JSON 사이드카 파싱, 사진 매칭, 이상치 필터 |
| `RouteVisualizationService` | 날짜별 동선 조합과 지도 응답 데이터 생성 |

- `TakeoutIngestService`는 zip의 로컬 경로를 받아 `PhotoLocation[]`을 반환하는 HTTP 요청 컨텍스트 비의존 순수 처리 서비스다.
- 사진 수는 200장 이하로 제한하며 동기 처리한다. 큐 인프라는 사용하지 않는다.
- 데이터 접근은 CI4 Model(`model(XxxModel::class)`)로 수행하고, Controller는 얇게 유지하며 비즈니스 로직은 Service에 둔다.

## 데이터 및 저장 정책

- 테이블: `photo_locations`(좌표, 시간, `source_item_id`, `thumbnail_path`), `oauth_tokens`(refresh/access 토큰 암호화 저장)
- `source_item_id`는 Google mediaItemId가 아니라 업로드 zip의 사진 파일명이며, 사용자별 유니크로 재업로드 idempotency를 보장한다.
- 원본 이미지 파일은 저장하지 않는다. zip 처리용 임시 디렉터리와 업로드 zip은 성공·실패와 무관하게 처리 완료 즉시 삭제한다.
- 예외적으로 가로 300px 썸네일만 `writable/uploads/thumbnails/`에 보관하고 `photo_locations.thumbnail_path`에 기록한다.
- 직전 지점 대비 시속 200km 초과 같은 좌표 이상치는 필터링한다.

## 로컬 검증

- CI/CD가 없으므로 머지 전 `composer ci`(CS Fixer → PHPStan → PHPUnit)를 실행한다. `composer check`는 CS Fixer를 포함하지 않는다.
- JSON 사이드카 파싱과 이상치 필터링은 순수 로직이므로 PHPUnit 단위 테스트로 반드시 커버한다.
- OAuth 콜백, zip 업로드, 지도 API 같은 런타임 표면을 변경하면 실제 구동도 확인한다.
- zip 업로드 성공 경로의 실제 파일 이동은 PHP `is_uploaded_file()` 제약으로 자동화하기 어려우므로 실제 브라우저에서 수동 확인한다.

## 보안 유의사항

- OAuth refresh token은 암호화해 `oauth_tokens`에 저장한다. 토큰을 응답이나 로그에 노출하지 않는다.
- Google Client ID/Secret 및 암호화 키는 `.env`에서만 관리하며 코드에 하드코딩하지 않는다.
- 무거운 동기 처리인 zip 업로드에는 사용자별 시간당 업로드 횟수 제한을 적용한다.
- 업로드 zip 크기와 사진 수 200장 상한을 애플리케이션 레벨에서 강제한다.
