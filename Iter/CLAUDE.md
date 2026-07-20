# Iter (Google Photos GPS 동선 시각화) 프로젝트 가이드

> 저장소 공통 규칙은 [`../CLAUDE.md`](../CLAUDE.md)에서 상속된다(PR 없이 직접 머지, CI/CD 없음·로컬 검증).
> 이 파일은 `Iter/` 프로젝트 고유의 스택·아키텍처·규칙만 다룬다.
> 전체 요구사항 명세는 [`docs/photo-gps-tracker-spec.md`](docs/photo-gps-tracker-spec.md)를 참고한다.

## 프로젝트 개요

구글 포토 Picker UI에서 사용자가 사진을 직접 선택하면, 원본 파일을 다운로드해 EXIF
메타데이터(촬영 시간·GPS 좌표)를 추출하고, 이를 지도 위에 **날짜별 이동 동선**(마커 + 경로선)으로
시각화하는 서비스. 프로젝트명 *Iter*(라틴어: 여정·경로)는 이 "동선"을 뜻한다.

### 핵심 전제 (반드시 숙지)

Google Photos API의 `mediaMetadata`는 `creationTime`·`width`·`height`·카메라 정보만 제공하며
**GPS 좌표는 API 응답에 포함되지 않는다.** 위치 정보는 원본 파일의 EXIF 바이너리 안에만 존재하므로,
반드시 `baseUrl`로 원본을 다운로드한 뒤 **직접 EXIF를 파싱**해야 한다. `baseUrl`은 약 **60분 후 만료**되므로
발급 즉시 처리한다.

## 기술 스택

- **백엔드**: PHP 8.2+ / CodeIgniter 4
- **인증**: Google OAuth2 — 스코프 `https://www.googleapis.com/auth/photospicker.mediaitems.readonly`
- **사진 선택**: Google Photos Picker API (세션 생성 → `pickerUri` 리다이렉트 → 폴링 → 선택 항목 조회)
- **EXIF 파싱**: PHP 내장 `exif_read_data()` + HEIC 등 예외 포맷은 `exiftool` 바이너리(`shell_exec`) 또는 `php-exif` 라이브러리 병행
- **원본 다운로드**: `curl_multi_init` 병렬 다운로드(요청당 최대 10장 제한 덕에 큐 없이 동기 처리)
- **DB**: MySQL
- **지도 시각화**: Leaflet.js + OpenStreetMap (날짜별 색상 구분 마커·경로선)

이 프로젝트의 PHP(CodeIgniter 4) 코드는 부모 저장소들의 전역 PHP 규칙
([`~/.claude/rules/code-style.md`](~/.claude/rules/code-style.md),
[`~/.claude/rules/security.md`](~/.claude/rules/security.md),
[`~/.claude/rules/testing.md`](~/.claude/rules/testing.md),
[`~/.claude/rules/api-design.md`](~/.claude/rules/api-design.md))을 그대로 따른다.

## 아키텍처 — 서비스 클래스 경계

지금은 CI4 모놀리스로 시작하되, 향후 별도 서비스/워커(SQS 등)로 분리하기 쉽도록
클래스 레벨에서 경계를 미리 나눈다.

| 서비스 | 책임 |
|--------|------|
| `GooglePhotosAuthService` | OAuth2 로그인/콜백/토큰 발급·갱신 |
| `PhotoPickerService` | Picker 세션 생성·폴링·선택 항목 목록 조회 |
| `PhotoIngestService` | 원본 병렬 다운로드 + EXIF 추출 (핵심 로직) |
| `RouteVisualizationService` | 날짜별 동선 조합·지도 응답 데이터 생성 |

- `PhotoIngestService`는 **HTTP 요청 컨텍스트에 의존하지 않는 순수 함수형**으로 설계한다.
  입력 `mediaItemId[] + access token` → 출력 `[{lat, lng, taken_at, media_item_id}, ...]`.
  사용자 수가 늘면 이 서비스만 큐 기반 워커로 교체하는 점진적 전환을 노린다.
- 데이터 접근은 CI4 Model(`model(XxxModel::class)`) 경유. 비즈니스 로직은 Controller가 아닌 Service에 캡슐화(Controller는 얇게).

## 데이터 · 저장 정책

- 테이블: `photo_locations`(좌표·시간·`google_media_item_id`·`thumbnail_path`), `oauth_tokens`(refresh/access 토큰 **암호화** 저장). 상세 스키마는 명세 6절 참고.
- **원본 이미지 파일은 저장하지 않는다.** EXIF 추출 직후 다운로드한 원본(풀사이즈) 임시 파일은 즉시 폐기한다.
- **예외 — 가로 300px 썸네일만 보관**: EXIF 추출 성공 시 원본을 폐기하기 전에 가로 300px 썸네일을 생성해 `writable/uploads/thumbnails/`에 저장하고, 경로를 `photo_locations.thumbnail_path`에 함께 기록한다. 썸네일은 지도 미리보기 표시용으로, 풀사이즈 원본과 달리 재조회 없이 즉시 서빙 가능해야 하므로 **이것만 캐싱 예외**로 둔다.
- 풀사이즈 원본을 다시 표시해야 하면(썸네일이 아닌 원본 화질 필요 시) 그 시점에 API로 재조회해 새 `baseUrl`을 발급받는다(원본은 캐싱 불가, 썸네일만 예외).
- 좌표 이상치(예: 직전 지점 대비 시속 200km 초과)는 필터링해 지도 잡음을 제거한다.

## 로컬 검증

> ⚠️ 명세 8절은 GitHub Actions CI/CD 통합을 포함하지만, **이 모노레포는 CI/CD를 두지 않는다**
> ([`../CLAUDE.md`](../CLAUDE.md)). 명세의 CI/CD 항목은 아래 **로컬 검증**으로 대체한다.

- CI/CD가 없으므로 머지 전 아래를 **로컬에서** 직접 실행해 확인한다.
  - `composer ci`(CS Fixer → PHPStan → PHPUnit). `composer check`는 CS Fixer를 빠뜨리므로 사용하지 않는다.
  - EXIF 파싱·DMS→십진수 좌표 변환·이상치 필터링은 순수 로직이므로 **PHPUnit 단위 테스트로 반드시 커버**한다(외부 의존성 Mock).
- 런타임 표면(OAuth 콜백, Picker 폴링, 지도 API 엔드포인트)이 있는 변경은 테스트만으로 끝내지 않고 실제 구동까지 확인한다.

## 보안 유의사항

- OAuth **refresh token은 암호화**해 `oauth_tokens` 테이블에 저장(AES 권장). 응답·로그에 토큰을 노출하지 않는다.
- 시크릿(Google Client ID/Secret, 암호화 키)은 `.env`에서만 관리 — 코드 하드코딩 금지.
- **레이트 리밋**을 초기 단계부터 적용: 요청당 10장 제한 + 사용자당 시간당 세션 생성 횟수 제한(CI4 필터).
- Google Photos API는 프로젝트 단위 쿼터가 있으므로 사용자 증가에 따른 쿼터 소진을 모니터링한다.
