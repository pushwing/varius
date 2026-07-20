# Iter (Google Photos GPS 동선 시각화) 프로젝트 가이드

> 저장소 공통 규칙은 [`../CLAUDE.md`](../CLAUDE.md)에서 상속된다(PR 없이 직접 머지, CI/CD 없음·로컬 검증).
> 이 파일은 `Iter/` 프로젝트 고유의 스택·아키텍처·규칙만 다룬다.
> 전체 요구사항 명세는 [`docs/photo-gps-tracker-spec.md`](docs/photo-gps-tracker-spec.md)를 참고한다(단, GPS 획득 방식은
> 아래 핵심 전제에 따라 명세와 다르게 **Google Takeout zip 업로드**로 대체됐다).

## 프로젝트 개요

사용자가 Google Takeout에서 직접 내보낸 사진 zip을 업로드하면, 안의 `.json` 사이드카에서
GPS·촬영 시각을 추출해 지도 위에 **날짜별 이동 동선**(마커 + 경로선)으로 시각화하는 서비스.
프로젝트명 *Iter*(라틴어: 여정·경로)는 이 "동선"을 뜻한다.

### 핵심 전제 (반드시 숙지)

Google Photos Picker API·Library API 는 **다운로드 원본에서 GPS EXIF 를 의도적으로 제거한다**
(Google 공식 문서: "download the image retaining all the Exif metadata except the location
metadata"). 즉 Picker로 사진을 선택해 원본을 다운로드하는 방식으로는 GPS 를 절대 얻을 수 없다.
Google 이 GPS 를 원본 그대로 제공하는 유일한 경로는 **Google Takeout**(사용자가 직접 요청하는
비동기 벌크 내보내기) 이며, Takeout 은 사진 파일 자체에서도 GPS 를 제거하지만 `.json` 사이드카
파일의 `geoData`(또는 `geoDataExif`) 필드에 별도로 보존한다. 이 프로젝트는 사용자가 Takeout 에서
직접 내보낸 zip 을 업로드하는 방식으로 이 제약을 우회한다.

## 기술 스택

- **백엔드**: PHP 8.2+ / CodeIgniter 4
- **인증**: Google OAuth2 — 스코프 `openid`·`email`·`profile`(사용자 식별용, Photos API 는 더 호출하지 않음)
- **GPS 획득**: 사용자가 `takeout.google.com`에서 직접 내보낸 zip 업로드(`POST /takeout/upload`) →
  `ZipArchive`로 압축 해제 → `.json` 사이드카(`geoData`/`geoDataExif`) 파싱
- **DB**: MySQL
- **지도 시각화**: Leaflet.js + OpenStreetMap (날짜별 색상 구분 마커·경로선)

이 프로젝트의 PHP(CodeIgniter 4) 코드는 부모 저장소들의 전역 PHP 규칙
([`~/.claude/rules/code-style.md`](~/.claude/rules/code-style.md),
[`~/.claude/rules/security.md`](~/.claude/rules/security.md),
[`~/.claude/rules/testing.md`](~/.claude/rules/testing.md),
[`~/.claude/rules/api-design.md`](~/.claude/rules/api-design.md))을 그대로 따른다.

## 아키텍처 — 서비스 클래스 경계

| 서비스 | 책임 |
|--------|------|
| `GooglePhotosAuthService` | OAuth2 로그인/콜백/토큰 발급·갱신(사용자 식별용) |
| `TakeoutIngestService` | zip 압축 해제 + JSON 사이드카 파싱 + 사진 매칭 + 이상치 필터 (핵심 로직) |
| `RouteVisualizationService` | 날짜별 동선 조합·지도 응답 데이터 생성 |

- `TakeoutIngestService`는 업로드된 zip 의 로컬 경로를 입력받아 `PhotoLocation[]`을 출력하는
  순수 처리 서비스다(HTTP 요청 컨텍스트 비의존). 200장 상한, 동기 처리(큐 인프라 없음).
- 데이터 접근은 CI4 Model(`model(XxxModel::class)`) 경유. 비즈니스 로직은 Controller가 아닌 Service에 캡슐화(Controller는 얇게).

## 데이터 · 저장 정책

- 테이블: `photo_locations`(좌표·시간·`source_item_id`·`thumbnail_path`), `oauth_tokens`(refresh/access 토큰 **암호화** 저장).
- `source_item_id`는 Google mediaItemId 가 아니라 **업로드된 zip 안 사진 파일명**이다(사용자당 유니크, 재업로드 idempotency 보장).
- **원본 이미지 파일은 저장하지 않는다.** zip 압축 해제 후 처리에 쓴 임시 디렉터리는 처리 완료 즉시 통째로 삭제한다.
- **예외 — 가로 300px 썸네일만 보관**: 좌표 추출 성공 시 원본을 폐기하기 전에 가로 300px 썸네일을 생성해 `writable/uploads/thumbnails/`에 저장하고, 경로를 `photo_locations.thumbnail_path`에 함께 기록한다. 썸네일은 지도 미리보기 표시용으로, 원본과 달리 재조회 없이 즉시 서빙 가능해야 하므로 **이것만 캐싱 예외**로 둔다.
- 좌표 이상치(예: 직전 지점 대비 시속 200km 초과)는 필터링해 지도 잡음을 제거한다.
- 업로드 zip 자체도 처리 완료(성공·실패 무관) 즉시 삭제한다.

## 로컬 검증

> ⚠️ 명세 8절은 GitHub Actions CI/CD 통합을 포함하지만, **이 모노레포는 CI/CD를 두지 않는다**
> ([`../CLAUDE.md`](../CLAUDE.md)). 명세의 CI/CD 항목은 아래 **로컬 검증**으로 대체한다.

- CI/CD가 없으므로 머지 전 아래를 **로컬에서** 직접 실행해 확인한다.
  - `composer ci`(CS Fixer → PHPStan → PHPUnit). `composer check`는 CS Fixer를 빠뜨리므로 사용하지 않는다.
  - JSON 사이드카 파싱·이상치 필터링은 순수 로직이므로 **PHPUnit 단위 테스트로 반드시 커버**한다(외부 의존성 Mock).
- 런타임 표면(OAuth 콜백, zip 업로드, 지도 API 엔드포인트)이 있는 변경은 테스트만으로 끝내지 않고 실제 구동까지 확인한다. zip 업로드의 "성공 경로"(실제 파일 이동)는 PHP `is_uploaded_file()` 제약으로 자동화 테스트가 불가능해 **실제 브라우저로 수동 확인**한다.

## 보안 유의사항

- OAuth **refresh token은 암호화**해 `oauth_tokens` 테이블에 저장(AES 권장). 응답·로그에 토큰을 노출하지 않는다.
- 시크릿(Google Client ID/Secret, 암호화 키)은 `.env`에서만 관리 — 코드 하드코딩 금지.
- **레이트 리밋**을 적용: zip 업로드는 무거운 동기 처리이므로 사용자당 시간당 업로드 횟수를 제한한다(CI4 필터, `SessionRateLimitFilter` 재사용).
- 업로드 zip 크기·개수(200장) 상한을 애플리케이션 레벨에서 강제해 남용을 방지한다.
