# Pagus 셋업 가이드

파구스(Pagus)를 로컬에서 처음 실행하기 위한 문서다. 애플리케이션은 CodeIgniter 4와 MySQL을 사용하며, 웹 document root는 `public/`이어야 한다.

## 1. 사전 요구사항

- PHP 8.2 이상 및 CodeIgniter 4 요구 확장
- Composer
- MySQL 8.0 이상
- Kakao Developers 앱(지도와 주소·장소 검색을 사용할 때 필요)
- 선택 사항: `pagus.test`를 연결할 수 있는 Caddy 또는 다른 로컬 웹 서버

PHP와 Composer가 설치되어 있는지 확인한다.

```bash
php --version
composer --version
mysql --version
```

## 2. 소스와 의존성 준비

저장소 루트의 `Pagus/` 디렉터리에서 실행한다.

```bash
composer install
cp .env.example .env
php spark key:generate
```

이미 `.env`가 있다면 덮어쓰지 말고 기존 설정을 보존한다. `.env`와 API 키·비밀번호는 커밋하지 않는다.

## 3. 서비스 도메인 설정

`app.baseURL`은 예시 도메인을 그대로 사용하지 말고, 현재 배포하는 서비스의 자기 도메인으로 설정한다. 반드시 프로토콜(`http://` 또는 `https://`)을 포함하고 끝에 `/`를 붙인다.

```dotenv
# 로컬 예시
app.baseURL = 'http://pagus.test/'

# 실제 서비스 예시 — 자신의 도메인으로 교체
app.baseURL = 'https://맛집서비스.example/'
```

실제 서비스에서는 `https://맛집서비스.example/` 부분을 운영 도메인으로 교체해야 한다. 이 값은 링크·리다이렉트·외부 연동 기준이 되므로 다음 설정과 동일한 도메인을 사용한다.

- 웹 서버의 호스트 및 TLS 인증서
- `app/Config/App.php`의 `allowedHostnames`에 등록된 호스트
- Kakao Developers 앱의 Web 플랫폼 허용 도메인

로컬 공유 Caddy를 사용할 때만 `http://pagus.test/`를 사용한다.

## 4. MySQL 데이터베이스 준비

개발용 데이터베이스와 사용자를 준비한다. 아래 이름은 `.env` 예시와 맞춘 기본값이며, 실제 환경에 맞게 변경할 수 있다.

```sql
CREATE DATABASE pagus
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

CREATE USER 'pagus'@'localhost' IDENTIFIED BY '개발용_비밀번호';
GRANT ALL PRIVILEGES ON pagus.* TO 'pagus'@'localhost';
FLUSH PRIVILEGES;
```

`.env`의 `app.baseURL`은 위 서비스 도메인 규칙에 따라 먼저 설정하고, `database.default.*` 값은 위 데이터베이스에 맞춘다.

```dotenv
# 반드시 현재 서비스 도메인으로 교체한다.
app.baseURL = 'http://pagus.test/'
database.default.hostname = localhost
database.default.database = pagus
database.default.username = pagus
database.default.password = 개발용_비밀번호
database.default.DBDriver = MySQLi
database.default.port = 3306
```

테스트는 운영 데이터베이스가 아닌 별도 테스트 데이터베이스를 사용한다. 테스트 환경의 `database.tests.*` 설정은 로컬 `.env`에 추가해 관리하고, 실제 비밀번호는 출력하거나 커밋하지 않는다.

## 5. Kakao 설정

Kakao Maps 지도, 주소 검색, 운영자용 장소 참고 데이터 검색을 사용하려면 Kakao Developers에서 앱과 키를 준비해야 한다. REST API 키와 JavaScript 키는 서로 다른 키이므로 올바른 항목에 각각 입력한다.

### 5.1 Kakao Developers에서 앱 만들기

1. [Kakao Developers](https://developers.kakao.com/)에 카카오 계정으로 로그인한다.
2. `내 애플리케이션`에서 `애플리케이션 추가하기`를 선택하고 앱 이름과 사업자 정보를 입력한다.
3. 생성한 앱의 `앱 키` 화면에서 다음 값을 확인한다.
   - `REST API 키`: 주소 검색과 장소 참고 데이터 검색용
   - `JavaScript 키`: 브라우저 Kakao Maps SDK용
4. 앱의 플랫폼 설정에서 `Web 플랫폼 등록`을 선택한다.
5. 현재 서비스의 호스트를 사이트 도메인으로 등록한다.
   - 로컬: `http://pagus.test`
   - 운영: `https://서비스의-자기-도메인.example`
6. `app.baseURL`의 호스트, 웹 서버의 호스트, Kakao Web 플랫폼 도메인이 서로 같은지 확인한다. 운영 도메인을 바꾸면 세 곳을 함께 수정한다.

Kakao Developers의 메뉴명이나 사용 승인 절차가 변경될 수 있으므로, 앱의 Kakao Maps JavaScript SDK와 Local API 사용 가능 상태도 함께 확인한다.

### 5.2 Pagus `.env`에 키 입력하기

`composer.json`과 `spark`가 있는 Pagus 디렉터리의 `.env` 파일을 연다. `.env.example`을 복사해 만들 수 있다.

```bash
cd /Users/jongwonbyun/claude-works/varius/Pagus
cp .env.example .env
```

`.env`의 다음 항목에 발급받은 값을 입력한다.

```dotenv
# Kakao Developers의 REST API 키
kakaolocal.apiKey = 발급받은_REST_API_키

# Kakao Developers의 JavaScript 키
kakaomaps.jsKey = 발급받은_JavaScript_키
```

- `kakaolocal.apiKey`는 서버가 Kakao Local API를 호출할 때 사용한다. 운영자 맛집 등록 화면의 주소 검색과 장소 참고 데이터 검색에 사용된다.
- `kakaomaps.jsKey`는 공개 화면의 브라우저에 전달된다. 따라서 JavaScript 키에는 반드시 Web 플랫폼 도메인 제한을 설정한다.
- 키가 비어 있거나 외부 API가 실패해도 운영자는 주소·좌표를 직접 입력할 수 있다.
- 키를 코드, 로그, 스크린샷, 커밋에 넣지 않는다. `.env`는 Git에 추가하지 않는다.

## 6. 마이그레이션과 운영자 계정

### 6.1 반드시 실행할 디렉터리

마이그레이션과 시딩 명령은 모노레포 상위 디렉터리가 아니라 `composer.json`과 `spark` 파일이 있는 Pagus 애플리케이션 디렉터리에서 실행한다.

```bash
cd /Users/jongwonbyun/claude-works/varius/Pagus
pwd
```

출력이 `.../varius/Pagus`인지 확인한 뒤 아래 명령을 실행한다. 다른 경로에서 실행하면 `spark` 또는 `.env`를 찾지 못할 수 있다.

### 6.2 데이터베이스 마이그레이션

마이그레이션 파일은 `app/Database/Migrations/`에 있으며, 적용 이력은 데이터베이스의 `migrations` 테이블에 기록된다.

```bash
# 현재 적용 상태 확인
php spark migrate:status

# 아직 적용되지 않은 모든 마이그레이션 적용
php spark migrate

# 적용 후 상태 재확인
php spark migrate:status
```

`php spark migrate`는 테이블과 인덱스를 생성·변경하는 명령이고, 운영자 계정이나 맛집 데이터를 생성하는 명령은 아니다. 새 migration이 추가될 때마다 동일한 Pagus 디렉터리에서 이 명령을 실행한다.

### 6.3 운영자 이메일·비밀번호 설정 위치

최초 운영자 계정의 이메일과 비밀번호는 `Pagus/.env`의 다음 두 항목에서 설정한다.

```dotenv
PAGUS_ADMIN_EMAIL = admin@서비스-도메인.example
PAGUS_ADMIN_PASSWORD = 12자_이상의_개발용_비밀번호
```

이 값은 `app/Database/Seeds/RoleAndAdminSeeder.php`가 최초 계정을 만들 때 읽는다.

- `PAGUS_ADMIN_PASSWORD`는 12자 이상이어야 한다.
- 비밀번호는 평문으로 DB에 저장되지 않고 `password_hash()` 결과로 저장된다.
- `PAGUS_ADMIN_EMAIL`을 생략하면 기본값 `admin@pagus.test`가 사용된다.
- 시더는 해당 이메일의 사용자가 없을 때만 계정을 생성한다. 이미 같은 이메일의 계정이 있으면 `.env` 값을 바꿔도 이메일·비밀번호가 변경되지 않는다.
- 현재 운영자 이메일·비밀번호를 웹 화면에서 변경하거나 재설정하는 기능은 제공하지 않는다. 운영 환경에서는 별도 보안 운영 절차를 마련해야 한다.

로컬에서 아직 계정이 생성되지 않았다면 `.env`를 저장한 뒤 아래처럼 시딩한다.

```bash
cd /Users/jongwonbyun/claude-works/varius/Pagus
php spark db:seed DatabaseSeeder
```

한 번의 명령에만 다른 값을 사용하려면 셸 환경변수로 덮어쓸 수 있다. 이 방식은 `.env` 파일을 수정하지 않는다.

```bash
cd /Users/jongwonbyun/claude-works/varius/Pagus
PAGUS_ADMIN_EMAIL='admin@pagus.test' \
PAGUS_ADMIN_PASSWORD='12자 이상의 개발용 비밀번호' \
php spark db:seed DatabaseSeeder
```

`DatabaseSeeder`는 `RoleAndAdminSeeder`를 호출해 `admin` 역할과 운영자 계정을 만든다. 마이그레이션을 먼저 실행하지 않으면 `roles`·`users` 테이블이 없어 시딩할 수 없다.

### 6.4 마이그레이션 되돌리기

로컬 테스트 데이터베이스에서만 실행한다.

```bash
cd /Users/jongwonbyun/claude-works/varius/Pagus
php spark migrate:rollback
```

이 명령은 가장 최근 마이그레이션 배치를 되돌린다. 운영 데이터베이스에 실행하지 말고, 데이터가 있는 환경에서는 먼저 백업과 복구 절차를 확인한다.

운영자 로그인 주소는 `/login`이며, 로그인 후 `/admin`에서 맛집·카테고리·사진·후기·문의를 관리한다.

## 7. 쓰기 디렉터리

CI4 세션과 맛집 사진이 `writable/` 아래에 저장되므로 실행 사용자가 쓰기 권한을 가져야 한다.

```bash
mkdir -p writable/session writable/uploads/restaurants
```

사진은 웹 document root인 `public/` 밖에 저장된다. 업로드는 JPG·PNG·WebP, 파일당 5MB 이하로 제한된다.

## 8. 애플리케이션 실행

### 기본 실행

Caddy 없이 CI4 개발 서버로 실행한다.

```bash
php spark serve
```

명령이 출력하는 개발 서버 주소로 접속한다.

### 공유 Caddy 사용

이 저장소의 기본 주소는 `http://pagus.test/`다. 공유 Caddy 프록시가 구성된 환경에서는 Pagus upstream을 로컬 포트에 바인딩해 실행한다.

```bash
php spark serve --host 127.0.0.1 --port 8311
```

Caddy가 `pagus.test`를 위 upstream으로 연결하도록 별도 등록되어 있어야 한다. Caddy와 `dev-proxy` 설정은 이 애플리케이션 저장소에 포함되지 않으므로, 해당 환경의 프록시 운영 문서를 따른다.

## 9. 검증

코드 변경 후 저장소 규칙에 따라 다음 순서로 실행한다.

```bash
composer check
composer analyse
composer test
```

각 명령의 역할은 다음과 같다.

- `composer check`: PHP-CS-Fixer dry-run과 `git diff --check`
- `composer analyse`: PHPStan 정적 분석
- `composer test`: `tests/` PHPUnit 단위 테스트

화면과 외부 연동은 별도로 확인한다.

1. `http://pagus.test/`에서 공개 맛집 목록과 Kakao 지도 마커를 확인한다.
2. 맛집 상세에서 사진, 후기, 장소 공유, Kakao 길찾기 링크를 확인한다.
3. 운영자 로그인 후 맛집·카테고리·사진·후기·문의 관리 화면을 확인한다.
4. 주소 검색과 장소 참고 데이터 검색이 성공·실패할 때 모두 직접 입력 경로가 유지되는지 확인한다.

## 문제 해결

- `Class ... not found`: `composer dump-autoload --no-interaction` 후 다시 실행한다.
- 데이터베이스 연결 실패: MySQL 실행 여부와 `.env`의 hostname·database·username·password·port를 확인한다.
- 세션 또는 사진 저장 실패: `writable/session`과 `writable/uploads/restaurants`의 존재 및 쓰기 권한을 확인한다.
- 지도가 표시되지 않음: `kakaomaps.jsKey`와 Kakao Web 플랫폼 허용 도메인을 확인한다.
- 주소·장소 검색 실패: `kakaolocal.apiKey`, 외부 API 호출 가능 여부, Kakao API 사용 한도를 확인한다.
- `/admin`이 `/login`으로 이동함: 운영자 seed 실행 여부와 로그인 계정의 활성 상태를 확인한다.
