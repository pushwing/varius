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

## 3. MySQL 데이터베이스 준비

개발용 데이터베이스와 사용자를 준비한다. 아래 이름은 `.env` 예시와 맞춘 기본값이며, 실제 환경에 맞게 변경할 수 있다.

```sql
CREATE DATABASE pagus
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

CREATE USER 'pagus'@'localhost' IDENTIFIED BY '개발용_비밀번호';
GRANT ALL PRIVILEGES ON pagus.* TO 'pagus'@'localhost';
FLUSH PRIVILEGES;
```

`.env`의 `database.default.*` 값을 위 데이터베이스에 맞춘다.

```dotenv
CI_ENVIRONMENT = development
app.baseURL = 'http://pagus.test/'
database.default.hostname = localhost
database.default.database = pagus
database.default.username = pagus
database.default.password = 개발용_비밀번호
database.default.DBDriver = MySQLi
database.default.port = 3306
```

테스트는 운영 데이터베이스가 아닌 별도 테스트 데이터베이스를 사용한다. 테스트 환경의 `database.tests.*` 설정은 로컬 `.env`에 추가해 관리하고, 실제 비밀번호는 출력하거나 커밋하지 않는다.

## 4. Kakao 설정

Kakao Developers에서 앱을 만든 뒤 `.env`에 다음 두 키를 설정한다.

```dotenv
# 장소·주소 검색용 REST API 키
kakaolocal.apiKey =

# 브라우저 Kakao Maps SDK용 JavaScript 키
kakaomaps.jsKey =
```

두 키는 서로 다른 값이다.

- `kakaolocal.apiKey`: 운영자 맛집 등록 화면의 주소 검색과 장소 참고 데이터 검색에 사용한다.
- `kakaomaps.jsKey`: 공개 지도 화면에서 사용한다. Kakao Developers의 Web 플랫폼에 `http://pagus.test`를 허용 도메인으로 등록한다.

키가 없거나 외부 API가 실패해도 운영자는 주소·좌표를 직접 입력할 수 있다. 외부 API 키는 코드·로그·커밋에 넣지 않는다.

## 5. 마이그레이션과 운영자 계정

테이블을 생성하고 최초 운영자 계정을 만든다.

```bash
php spark migrate
PAGUS_ADMIN_EMAIL='admin@pagus.test' \
PAGUS_ADMIN_PASSWORD='12자 이상의 개발용 비밀번호' \
php spark db:seed DatabaseSeeder
```

시더는 `admin` 역할과 해당 이메일의 운영자 계정을 처음 생성한다. 기존 동일 이메일 계정의 비밀번호는 갱신하지 않으므로, 계정 정보를 바꿔야 할 때는 별도 운영 절차를 사용한다.

운영자 로그인 주소는 `/login`이며, 로그인 후 `/admin`에서 맛집·카테고리·사진·후기·문의를 관리한다.

## 6. 쓰기 디렉터리

CI4 세션과 맛집 사진이 `writable/` 아래에 저장되므로 실행 사용자가 쓰기 권한을 가져야 한다.

```bash
mkdir -p writable/session writable/uploads/restaurants
```

사진은 웹 document root인 `public/` 밖에 저장된다. 업로드는 JPG·PNG·WebP, 파일당 5MB 이하로 제한된다.

## 7. 애플리케이션 실행

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

## 8. 검증

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

## 9. 마이그레이션 되돌리기

로컬 테스트 데이터베이스에서만 실행한다.

```bash
php spark migrate:rollback
```

이 명령은 가장 최근 마이그레이션 배치를 되돌린다. 데이터가 필요한 환경에서는 먼저 백업하고, 운영 데이터베이스에는 실행하지 않는다.

## 문제 해결

- `Class ... not found`: `composer dump-autoload --no-interaction` 후 다시 실행한다.
- 데이터베이스 연결 실패: MySQL 실행 여부와 `.env`의 hostname·database·username·password·port를 확인한다.
- 세션 또는 사진 저장 실패: `writable/session`과 `writable/uploads/restaurants`의 존재 및 쓰기 권한을 확인한다.
- 지도가 표시되지 않음: `kakaomaps.jsKey`와 Kakao Web 플랫폼 허용 도메인을 확인한다.
- 주소·장소 검색 실패: `kakaolocal.apiKey`, 외부 API 호출 가능 여부, Kakao API 사용 한도를 확인한다.
- `/admin`이 `/login`으로 이동함: 운영자 seed 실행 여부와 로그인 계정의 활성 상태를 확인한다.
