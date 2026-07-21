# 로컬 개발 환경 셋업

Iter 프로젝트를 로컬에서 처음 구동하는 개발자를 위한 절차입니다. 프로젝트 개요·아키텍처는
[`../README.md`](../README.md)와 [`../CLAUDE.md`](../CLAUDE.md)를 먼저 읽어두세요.

## 1. 사전 준비물

| 항목 | 버전 | 비고 |
|------|------|------|
| PHP | 8.2 이상 | `ext-zip`(Takeout zip 압축 해제), `ext-gd`(썸네일 생성), `ext-intl`, `ext-mbjson` 등 CI4 표준 확장 필요 |
| Composer | 2.x | `composer.json` 의존성 설치용 |
| MySQL | 5.7+ / 8.x | `photo_locations`, `oauth_tokens`, `users` 테이블 |
| Google Cloud 프로젝트 | - | OAuth2 클라이언트(사용자 신원 확인용) 발급 |

Node.js는 필요 없습니다 — 프론트엔드는 별도 빌드 없이 Leaflet.js(CDN)를 뷰에서 바로 사용합니다.

## 2. 의존성 설치 및 `.env` 생성

```bash
composer install
cp env .env
```

## 3. `.env` 필수 값 채우기

`.env`는 커밋하지 않습니다(`.gitignore` 등록됨). 아래 키를 채웁니다.

```ini
# 데이터베이스
database.default.hostname = localhost
database.default.database = iter
database.default.username = root
database.default.password = root
database.default.DBDriver = MySQLi
database.default.port     = 3306

# 토큰 암호화 키 — 아래 4단계에서 자동 채워짐

# Google OAuth2 (사용자 식별 전용)
google.oauth.clientId     = '...'
google.oauth.clientSecret = '...'
google.oauth.redirectUri  = 'http://localhost:8080/auth/google/callback'

# 업로드 레이트 리밋(선택, 기본 10회/시간)
ratelimit.session.per_hour = 10
```

테스트용 DB를 별도로 구성한다면 `database.tests.*` 키도 채웁니다(`phpunit.dist.xml` 기본값:
DB `tests`, 사용자 `tests_user`, 접두어 `tests_`).

## 4. 암호화 키 생성

`oauth_tokens.refresh_token`/`access_token` 암호화에 필수입니다.

```bash
php spark key:generate
```

`.env`의 `encryption.key`가 자동으로 채워집니다. 값을 직접 만들거나 다른 환경과 공유하지 마세요.

## 5. Google OAuth 클라이언트 등록

1. [Google Cloud Console](https://console.cloud.google.com/)에서 프로젝트를 선택(또는 생성)합니다.
2. **API 및 서비스 → OAuth 동의 화면**에서 앱을 구성합니다(테스트 단계면 "테스트 사용자"에 본인 계정 추가).
3. **사용자 인증 정보 → OAuth 클라이언트 ID 만들기 → 웹 애플리케이션**을 선택합니다.
4. **승인된 리디렉션 URI**에 `.env`의 `google.oauth.redirectUri`와 정확히 일치하는 값을 등록합니다
   (예: `http://localhost:8080/auth/google/callback`).
5. 발급된 클라이언트 ID/시크릿을 `.env`의 `google.oauth.clientId`/`google.oauth.clientSecret`에 채웁니다.
6. 스코프는 `openid`·`email`·`profile`만 필요합니다(`app/Config/GoogleOAuth.php`에 고정 정의됨) —
   Google Photos API 권한은 요청하지 않습니다.

## 6. DB 마이그레이션

```bash
php spark migrate
```

`users`, `oauth_tokens`, `photo_locations`(썸네일 경로 포함) 테이블이 생성됩니다.

## 7. 서버 구동 및 로그인 확인

```bash
php spark serve
```

브라우저에서 `http://localhost:8080/auth/google`로 접속 → Google 로그인 → 콜백 처리 후
`/`로 리다이렉트되면 정상입니다. 로그인 상태에서 `/map`으로 이동하면 지도 페이지가 뜹니다.

## 8. Google Takeout zip 준비 및 업로드 확인

1. [takeout.google.com](https://takeout.google.com)에서 **Google 포토만** 선택해 내보내기를 요청합니다
   (전체 계정 내보내기보다 훨씬 빠릅니다). 완료 알림 후 zip을 다운로드합니다.
2. `/map` 페이지의 업로드 UI에서 zip을 선택해 업로드합니다(`POST /takeout/upload`).
3. 정상 처리되면 사이드바에 월/일별 동선 트리가 나타나고, 지도에 날짜별 마커·경로선이 표시됩니다.

> zip 업로드의 실제 파일 이동 경로(`is_uploaded_file()` 검사)는 PHP 자동화 테스트로 재현할 수
> 없으므로, 이 흐름은 반드시 브라우저에서 실제로 한 번 확인하세요. 업로드 상한은 zip **500MB**,
> 추출 좌표 **200장**이며 초과분은 잘려서 저장됩니다.

## 9. 로컬 검증 (머지 전 필수)

```bash
composer ci      # CS Fixer(cs) → PHPStan(analyse) → PHPUnit(test) 순차 실행
```

개별 실행:

```bash
composer cs-fix   # PHP-CS-Fixer 자동 수정
composer analyse  # PHPStan 정적 분석
composer test     # PHPUnit
```

이 저장소는 CI/CD 파이프라인을 두지 않으므로([`../../CLAUDE.md`](../../CLAUDE.md) 참고),
위 검증은 머지 전 로컬에서 직접 실행해야 합니다.

## 트러블슈팅

| 증상 | 원인 · 해결 |
|------|------------|
| `/auth/google` 리다이렉트 후 `Invalid OAuth state` | 세션 쿠키가 유지되지 않음 — `app.baseURL`이 실제 접속 URL과 일치하는지, 브라우저가 쿠키를 차단하지 않는지 확인 |
| 콜백 후 400 `OAuth callback failed` | `google.oauth.clientId`/`clientSecret`/`redirectUri` 오타, 또는 Cloud Console에 등록한 리디렉션 URI와 불일치 |
| zip 업로드 시 413 "파일이 너무 큽니다" | PHP `upload_max_filesize`/`post_max_size`가 500MB보다 작음(mod_php 환경 특히 주의) — `php.ini` 상향 후 재시작 |
| zip 업로드 시 422 "zip 파일만 업로드할 수 있습니다" | 확장자가 `.zip`이 아니거나 Takeout이 여러 파트로 분할(`.zip`, `.z01` 등)한 경우 — 파트를 병합하거나 단일 zip으로 재내보내기 |
| 업로드는 성공했는데 지점이 하나도 안 뜸 | 사진에 위치 정보가 없거나 200장 상한 초과로 잘렸을 수 있음 — 응답의 `totalCandidates`/`capped` 값을 확인 |
| 429 "요청이 너무 잦습니다" | 사용자당(미로그인 시 IP당) 시간당 업로드 상한(기본 10회) 도달 — `ratelimit.session.per_hour`로 조정 가능 |
| 마이그레이션 실패 | `.env`의 `database.default.*` 접속 정보 확인, 대상 DB가 미리 생성돼 있는지 확인(`CREATE DATABASE iter`) |
