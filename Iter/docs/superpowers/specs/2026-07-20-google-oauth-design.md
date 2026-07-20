# Iter — Google OAuth2 인증 설계 (이슈 #13)

> 대상: `GooglePhotosAuthService` + `oauth_tokens` 저장·암호화. Iter의 첫 이슈이므로 CI4 프로젝트 스캐폴딩을 포함한다.
> 관련: 명세 4.1 / 6절(`docs/photo-gps-tracker-spec.md`), 이슈 #13.

## 1. 목표 / 완료 조건

- Google OAuth2 로그인 시작 → 콜백 → 토큰 저장 플로우가 동작한다.
- refresh/access token은 **암호화**해 `oauth_tokens`에 저장하고, 응답·로그에 원문을 노출하지 않는다.
- 시크릿(Client ID/Secret, 암호화 키)은 `.env`에서만 로드한다(하드코딩 금지).
- PHPUnit 테스트 + `composer ci`(CS Fixer → PHPStan → PHPUnit) 통과.

## 2. 확정된 설계 결정

| 결정 | 선택 | 이유 |
|------|------|------|
| 사용자 식별 | **최소 `users` 테이블** (google_sub UNIQUE) + 세션 user_id | `oauth_tokens.user_id` FK 무결성 확보, 향후 다수 사용자 확장에 자연스러움 |
| OAuth 구현 | **league/oauth2-client + league/oauth2-google** | 검증된 라이브러리, PHPStan 친화, 보안 직접구현 금지 규칙 준수 |
| 토큰 암호화 | **CI4 내장 Encryption**(AES-256-CTR + HMAC) | 프레임워크 관용, `.env` 키 관리·서명 검증 내장, AES 요구 충족 |

## 3. 프로젝트 스캐폴딩

기존 `docs/`·`CLAUDE.md`·`README.md`를 보존한 채 CI4를 스캐폴딩한다.

- 런타임 의존성: `codeigniter4/framework ^4.5`, `league/oauth2-client ^2.7`, `league/oauth2-google ^4.0`
- 개발 의존성: `friendsofphp/php-cs-fixer`, `phpstan/phpstan`, `phpunit/phpunit`(CI4 devkit 동봉 버전과 호환)
- 파일: `app/`, `public/index.php`, `writable/`, `spark`, `env`→`.env`, `phpunit.xml.dist`, `phpstan.neon`, `.php-cs-fixer.dist.php`, `.gitignore`
- composer 스크립트:
  - `cs-fix` — `php-cs-fixer fix` (자동수정)
  - `analyse` — `phpstan analyse`
  - `test` — `phpunit`
  - `ci` — CS 검증(`--dry-run --diff`) → `analyse` → `test` (CLAUDE.md 게이트와 일치)

## 4. DB 스키마 (마이그레이션 2개)

```
users
  id            BIGINT UNSIGNED PK AI
  google_sub    VARCHAR(255)  UNIQUE  -- Google 계정 고유 식별자(sub)
  email         VARCHAR(255)  NULL
  name          VARCHAR(255)  NULL
  created_at    DATETIME
  updated_at    DATETIME

oauth_tokens
  id                       BIGINT UNSIGNED PK AI
  user_id                  BIGINT UNSIGNED  UNIQUE  FK→users.id
  refresh_token_encrypted  TEXT NOT NULL
  access_token_encrypted   TEXT NULL
  expires_at               DATETIME NULL
  updated_at               DATETIME NOT NULL
```

명세 6절 스키마를 따르되 `user_id`를 `users.id` FK로 연결한다.

## 5. OAuth 플로우

```
GET /auth/google
  → AuthController::redirect()
  → GooglePhotosAuthService::getAuthorizationUrl(state)
     - state = 세션에 저장한 CSRF 난수(콜백에서 대조)
     - access_type=offline, prompt=consent  (refresh_token 확보)
     - scope: openid, email, profile, https://www.googleapis.com/auth/photospicker.mediaitems.readonly
  → 302 redirect

GET /auth/google/callback?code=&state=
  → AuthController::callback()
  → state 대조(불일치 시 예외)
  → GooglePhotosAuthService::exchangeCode(code) → AccessToken(access·refresh·expires·id_token)
  → id_token/ownerDetails에서 sub·email·name 추출
  → UserModel::upsertByGoogleSub(sub, email, name) → user_id
  → GooglePhotosAuthService::storeTokens(user_id, accessToken) : 암호화 후 upsert
  → 세션에 user_id 저장, 성공 화면(토큰 원문 미표시)
```

## 6. 컴포넌트

### `app/Config/GoogleOAuth.php`
- `clientId` / `clientSecret` / `redirectUri` 를 `env()`로만 로드. `scopes` 상수 배열.

### `app/Services/GooglePhotosAuthService.php` (핵심)
HTTP 요청 컨텍스트에 의존하지 않는 순수 로직 위주. 협력 객체(league `Google` 프로바이더, `OAuthTokenModel`, `Encrypter`)는 생성자 주입.

| 메서드 | 책임 |
|--------|------|
| `getAuthorizationUrl(string $state): string` | 인가 URL 생성 |
| `exchangeCode(string $code): AccessTokenInterface` | code→token 교환 |
| `storeTokens(int $userId, AccessTokenInterface $token): void` | access/refresh 암호화 후 upsert |
| `getValidAccessToken(int $userId): string` | 저장 토큰 복호화, 만료(임박) 시 refresh로 갱신·재저장 후 반환 |
| `refreshAccessToken(int $userId): string` | refresh_token으로 access 갱신·재저장 |

- 암호화/복호화는 서비스 내부에서만. 로깅 시 토큰 값 제외.
- 만료 판정: `expires_at`에 안전 마진(예: 60초) 적용.

### 모델
- `app/Models/UserModel.php` — `upsertByGoogleSub(sub, email, name): int`.
- `app/Models/OAuthTokenModel.php` — `$allowedFields` 명시, `user_id` 기준 upsert 조회.

### `app/Controllers/AuthController.php`
얇게: 입력 검증 → 서비스 위임 → 응답. 로직은 서비스에.

### 라우트 (`app/Config/Routes.php`)
- `GET /auth/google` → `AuthController::redirect`
- `GET /auth/google/callback` → `AuthController::callback`

## 7. 테스트 (PHPUnit)

단위 테스트(league 프로바이더·Model Mock):

- `exchangeCode` — 프로바이더가 반환한 토큰을 그대로 전달.
- 암호화 라운드트립 — 저장값이 평문과 다르고, 복호화 시 원문 일치.
- `UserModel::upsertByGoogleSub` — 신규 insert / 기존 update 분기(통합 테스트, 트랜잭션 롤백).
- `getValidAccessToken` — 유효 시 그대로 반환 / 만료 임박 시 refresh 경로.
- state 불일치 시 콜백 예외.

## 8. 검증 & 수동 구동

- `composer ci` 그린(CS·PHPStan·PHPUnit).
- `php spark serve` 로 `/auth/google`이 올바른 Google authorization URL(스코프·access_type·state 포함)로 302 하는지 확인.
- 콜백 에러 처리(state 불일치·code 누락) 확인.
- **한계**: 실 Google 왕복(실제 code→token)은 실 client 자격증명이 필요하므로 이 환경에서 불가. 실 자격증명 기반 end-to-end는 사용자 수동 확인 단계로 남긴다(README/후속 이슈에 명시).

## 9. 보안 체크

- 시크릿·암호화 키는 `.env` 전용, `.env`는 `.gitignore`.
- 토큰 원문을 JSON 응답/로그에 노출 금지.
- CSRF: OAuth state 파라미터로 콜백 위조 방지.
- league 프로바이더로 서명·토큰 교환을 위임(암호·서명 직접구현 금지 규칙 준수).
