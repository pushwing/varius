# Pagus 셋업 및 배포 가이드

파구스(Pagus)를 소스에서 설치하고 실행·배포하는 방법을 설명한다. Pagus는 CodeIgniter 4와 MySQL을 사용하며, 웹 서버의 document root는 반드시 프로젝트의 `public/` 디렉터리여야 한다.

아래 문서의 `https://your-domain.example/`와 `your-domain.example`은 실제 서비스 도메인으로 교체한다. 예시 도메인을 그대로 사용하지 않는다.

## 1. 필요한 환경

### 개발·테스트 환경

- PHP 8.2 이상 및 CodeIgniter 4 요구 확장
- Composer
- MySQL 8.0 이상
- Kakao Developers 앱(지도·주소·장소 검색을 사용할 때)

### 웹호스팅 환경

- PHP 8.2 이상
- MySQL 8.0 이상
- Composer 또는 서버에 업로드할 준비가 된 Composer 의존성
- `public/`을 document root로 지정할 수 있는 호스팅
- 가능하면 SSH 또는 호스팅 터미널(마이그레이션·시딩 실행용)

### 가상서버(VPS) 환경

- Ubuntu 등 Linux 서버
- Nginx 또는 Apache
- PHP-FPM 8.2 이상 및 MySQL 8.0 이상
- 도메인 DNS를 서버 IP로 연결할 권한
- HTTPS 인증서 발급 환경

PHP·Composer·MySQL 설치 여부는 다음으로 확인한다.

```bash
php --version
composer --version
mysql --version
```

## 2. 소스 받기와 의존성 준비

먼저 사용자가 원하는 작업 디렉터리에서 Git 저장소를 받는다. 아래 `~/projects`는 예시이므로 원하는 디렉터리로 바꿔도 된다.

```bash
mkdir -p ~/projects
cd ~/projects
git clone https://github.com/pushwing/varius.git
cd varius/Pagus
pwd
```

clone이 끝나면 모노레포가 다음과 같이 만들어진다.

```text
~/projects/varius/
├── AGENTS.md
├── README.md
├── Iter/
├── Pagus/
│   ├── app/
│   ├── public/
│   ├── spark
│   ├── composer.json
│   └── .env.example
└── rtic/
```

이후 모든 Pagus 명령은 `composer.json`과 `spark`가 있는 `~/projects/varius/Pagus`에서 실행한다. 사용자가 다른 곳에 clone했다면 그 경로의 `varius/Pagus`로 바꾼다.

```bash
cd ~/projects/varius/Pagus
composer install
```

모노레포 상위 디렉터리에서 실행하면 Pagus의 `.env`, `vendor/`, `spark`를 찾지 못할 수 있다.

환경 설정 파일을 만든다.

```bash
cp .env.example .env
php spark key:generate
```

이미 `.env`가 있으면 덮어쓰지 않는다. `.env`, API 키, 데이터베이스 비밀번호는 커밋·공개하지 않는다.

## 3. 서비스 도메인 설정

`app.baseURL`은 현재 접근할 주소로 설정한다. 프로토콜을 포함하고 끝에 `/`를 붙인다. `php spark serve`로 테스트할 때는 아래 로컬 주소를 사용한다.

```dotenv
# php spark serve 테스트 주소
app.baseURL = 'http://localhost:8080/'
```

다음 항목은 같은 호스트를 기준으로 맞춰야 한다.

1. `.env`의 `app.baseURL`
2. 웹 서버의 virtual host/server name과 TLS 인증서
3. `app/Config/App.php`의 `allowedHostnames`
4. Kakao Developers의 Web 플랫폼 허용 도메인

운영 도메인이 `food.example.com`이라면 `app.baseURL`은 `https://food.example.com/`, Kakao Web 플랫폼 도메인은 `https://food.example.com`으로 설정한다. 도메인을 바꿀 때 네 항목을 함께 확인한다. 테스트 주소를 `localhost`로 사용할 때는 `app/Config/App.php`의 `allowedHostnames`에 `localhost`와 `127.0.0.1`을 등록한다.

## 4. MySQL 데이터베이스 준비

호스팅 패널 또는 MySQL 관리자 계정으로 서비스 전용 데이터베이스와 사용자를 만든다. 실제 운영 환경에서는 아래 값을 강한 비밀번호로 교체한다.

```sql
CREATE DATABASE pagus
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

CREATE USER 'pagus'@'localhost' IDENTIFIED BY '강한_데이터베이스_비밀번호';
GRANT ALL PRIVILEGES ON pagus.* TO 'pagus'@'localhost';
FLUSH PRIVILEGES;
```

호스팅 업체가 데이터베이스명·사용자명에 계정 접두사를 붙이면 패널에 표시된 전체 값을 사용한다. `.env`에 다음 값을 입력한다.

```dotenv
database.default.hostname = localhost
database.default.database = pagus
database.default.username = pagus
database.default.password = 강한_데이터베이스_비밀번호
database.default.DBDriver = MySQLi
database.default.port = 3306
```

테스트 DB와 운영 DB를 반드시 분리한다. 운영 DB를 테스트·rollback 대상으로 사용하지 않는다.

## 5. Kakao 설정

Kakao Maps 지도와 운영자 화면의 주소·장소 검색을 사용하려면 Kakao Developers에서 앱을 만들고 두 종류의 키를 발급받아야 한다.

### 5.1 Kakao Developers에서 앱과 키 만들기

1. [Kakao Developers](https://developers.kakao.com/)에 로그인한다.
2. `내 애플리케이션`에서 `애플리케이션 추가하기`를 선택한다.
3. 앱 이름과 필요한 사업자 정보를 입력해 앱을 생성한다.
4. 생성한 앱의 `앱 키` 화면에서 다음 두 값을 확인한다.
   - `REST API 키`: 서버 주소 검색·장소 참고 데이터 검색용
   - `JavaScript 키`: 브라우저 Kakao Maps SDK용
5. 앱의 플랫폼 설정에서 `Web 플랫폼 등록`을 선택한다.
6. 실제 서비스의 웹 도메인을 등록한다.
   - 운영: `https://your-domain.example`
   - 개발: 현재 개발에 사용하는 별도 호스트
7. Kakao Maps JavaScript SDK와 Kakao Local API를 사용할 수 있는지 앱 설정과 사용량 화면에서 확인한다.

Kakao Developers의 메뉴명·승인 조건은 변경될 수 있으므로 현재 콘솔 안내를 우선한다. JavaScript 키는 브라우저에 노출되므로 Web 플랫폼 도메인 제한을 반드시 설정한다.

### 5.2 `.env`에 키 입력하기

Pagus 디렉터리의 `.env`를 열어 다음 값을 입력한다.

```dotenv
# Kakao Developers의 REST API 키
kakaolocal.apiKey = 발급받은_REST_API_키

# Kakao Developers의 JavaScript 키
kakaomaps.jsKey = 발급받은_JavaScript_키
```

- `kakaolocal.apiKey`는 운영자 맛집 등록 화면의 주소 검색과 장소 참고 데이터 검색에 사용한다.
- `kakaomaps.jsKey`는 공개 화면의 지도 SDK에 사용한다.
- 두 키는 서로 바꾸어 입력하면 안 된다.
- 키가 없거나 외부 API가 실패하면 운영자는 주소·좌표를 직접 입력할 수 있다.
- 키는 코드·로그·스크린샷·커밋에 넣지 않는다.

## 6. 마이그레이션과 운영자 계정

### 6.1 실행 위치

마이그레이션과 시딩은 반드시 `composer.json`과 `spark`가 있는 Pagus 디렉터리에서 실행한다.

```bash
cd ~/projects/varius/Pagus
pwd
```

`pwd`가 Pagus 경로인지 확인한 뒤 명령을 실행한다. 모노레포 상위 경로에서 `php spark`를 실행하지 않는다.

### 6.2 마이그레이션 실행

마이그레이션 파일은 `app/Database/Migrations/`에 있고, 적용 이력은 DB의 `migrations` 테이블에 저장된다.

```bash
cd ~/projects/varius/Pagus
php spark migrate:status
php spark migrate
php spark migrate:status
```

`php spark migrate`는 테이블과 인덱스를 만들거나 변경한다. 운영자 계정은 다음 시딩 단계에서 생성한다. 새 버전의 소스를 배포할 때도 소스 파일을 교체한 뒤 같은 위치에서 `php spark migrate`를 실행한다.

### 6.3 운영자 이메일·비밀번호 설정

최초 운영자 계정의 이메일과 비밀번호는 Pagus 디렉터리의 `.env`에서 수정한다.

```dotenv
PAGUS_ADMIN_EMAIL = admin@your-domain.example
PAGUS_ADMIN_PASSWORD = 12자_이상의_강한_비밀번호
```

이 값은 `app/Database/Seeds/RoleAndAdminSeeder.php`가 시딩할 때 읽는다.

- `PAGUS_ADMIN_PASSWORD`는 12자 이상이어야 한다.
- 비밀번호는 `password_hash()`로 해시되어 저장된다.
- `PAGUS_ADMIN_EMAIL`을 생략하면 코드의 기본 이메일이 사용되므로 운영 환경에서는 직접 설정한다.
- 기존에 같은 이메일의 사용자가 있으면 시더는 계정을 새로 만들지 않는다. `.env`만 수정하고 다시 시딩해도 기존 이메일·비밀번호는 바뀌지 않는다.
- 현재 웹 화면에는 운영자 이메일·비밀번호 변경 및 재설정 기능이 없다. 운영 계정 변경은 별도의 보안된 운영 절차를 마련한 뒤 수행한다.

### 6.4 시딩 실행

마이그레이션을 먼저 완료한 뒤 같은 Pagus 디렉터리에서 실행한다.

```bash
cd ~/projects/varius/Pagus
php spark db:seed DatabaseSeeder
```

`.env`를 수정하지 않고 한 번의 명령에만 계정 값을 지정하려면 다음처럼 실행한다.

```bash
cd ~/projects/varius/Pagus
PAGUS_ADMIN_EMAIL='admin@your-domain.example' \
PAGUS_ADMIN_PASSWORD='12자 이상의 강한 비밀번호' \
php spark db:seed DatabaseSeeder
```

`DatabaseSeeder`가 `RoleAndAdminSeeder`를 호출해 `admin` 역할과 운영자 계정을 만든다. 마이그레이션을 먼저 실행하지 않으면 `roles`·`users` 테이블이 없어 시딩할 수 없다.

시딩 후 `/login`에서 로그인하고 `/admin`에 접근한다. `/admin`이 `/login`으로 이동하면 시딩 여부, 이메일, 비밀번호, 사용자 활성 상태를 확인한다.

### 6.5 마이그레이션 되돌리기

로컬 테스트 데이터베이스에서만 실행한다.

```bash
cd ~/projects/varius/Pagus
php spark migrate:rollback
```

이 명령은 가장 최근 마이그레이션 배치를 되돌린다. 운영 데이터베이스에 실행하지 말고, 데이터가 있는 환경에서는 먼저 백업과 복구 절차를 확인한다.

## 7. 쓰기 디렉터리

세션과 사진은 웹 루트가 아닌 `writable/` 아래에 저장한다.

```bash
cd ~/projects/varius/Pagus
mkdir -p writable/session writable/uploads/restaurants
```

웹 서버를 실행하는 계정이 두 디렉터리에 쓸 수 있어야 한다. 사진은 JPG·PNG·WebP만 허용하며 파일당 5MB 이하로 제한한다. `public/` 전체를 쓰기 가능하게 만들지 않는다.

## 8. 테스트 실행

개발자가 소스를 수정하거나 설치 상태를 확인할 때 사용하는 절차다. 운영 서비스는 이 개발 서버를 인터넷에 직접 노출하지 않는다.

```bash
cd ~/projects/varius/Pagus
php spark serve
```

브라우저에서 `http://localhost:8080/`으로 접속한다. 접속되지 않으면 `app.baseURL`이 `http://localhost:8080/`인지, `app/Config/App.php`의 `allowedHostnames`에 `localhost`가 있는지 확인한다.

코드 품질 테스트:

```bash
cd ~/projects/varius/Pagus
composer check
composer analyse
composer test
```

## 9. 웹호스팅에 배포하기

공유 웹호스팅은 관리 패널의 기능과 권한이 업체마다 다르다. 아래 순서에서 호스팅 패널의 실제 메뉴명을 사용한다.

### 9.1 도메인과 데이터베이스

1. 호스팅에 도메인을 연결하고 HTTPS를 활성화한다.
2. MySQL 데이터베이스와 전용 사용자를 만든다.
3. 실제 서비스 도메인으로 `.env`의 `app.baseURL`을 설정한다.
4. `.env`의 DB 접속 정보와 Kakao 키를 입력한다.
5. `app/Config/App.php`의 `allowedHostnames`에 실제 호스트가 포함되어 있는지 확인한다.

### 9.2 파일 업로드와 document root

가능하면 애플리케이션 파일과 공개 웹 루트를 분리한다.

```text
/home/계정/varius/                # Git clone 결과
/home/계정/varius/Pagus/          # app, system, writable, vendor, .env
/home/계정/public_html/           # Pagus/public의 내용 또는 public 디렉터리 연결
```

호스팅 패널에서 document root를 `Pagus/public`으로 직접 지정하는 방식을 권장한다. document root를 프로젝트 전체로 지정하면 `.env`, `app/`, `writable/`가 웹에서 노출될 수 있으므로 사용하지 않는다.

### 9.3 Composer와 초기화

SSH 또는 호스팅 터미널이 있으면 서버에서 실행한다.

```bash
cd /home/계정/varius/Pagus
composer install --no-dev --optimize-autoloader
php spark key:generate
php spark migrate:status
php spark migrate
php spark db:seed DatabaseSeeder
mkdir -p writable/session writable/uploads/restaurants
```

호스팅에서 Composer나 PHP CLI를 제공하지 않으면 배포 전에 같은 PHP 주 버전 환경에서 `composer install --no-dev --optimize-autoloader`를 실행해 `vendor/`를 함께 업로드한다. 그래도 `php spark migrate`와 `db:seed`를 실행할 SSH·터미널이 없다면 호스팅 업체에 CLI 실행 방법을 확인한 뒤 배포한다. SQL로 migration 테이블을 임의 조작하지 않는다.

### 9.4 웹호스팅 확인

- PHP 버전과 필요한 확장이 활성화되어 있는지 확인한다.
- URL rewrite와 `public/index.php` 연결이 정상인지 확인한다.
- `writable/session`과 `writable/uploads/restaurants`의 웹 서버 쓰기 권한을 확인한다.
- HTTPS에서 `app.baseURL`과 Kakao Web 플랫폼 도메인이 일치하는지 확인한다.
- `/`, `/restaurants/{id}`, `/login`, `/admin`을 실제 도메인에서 확인한다.
- 비공개 맛집이 공개 화면에 노출되지 않는지 확인한다.

## 10. 가상서버(VPS)에 배포하기

아래는 Linux VPS에서 Nginx와 PHP-FPM을 사용하는 예시다. 실제 운영 계정·경로·도메인으로 교체한다.

### 10.1 서버 준비

1. DNS의 A/AAAA 레코드를 서버 IP로 연결한다.
2. Nginx, PHP 8.2 이상, PHP-FPM, 필요한 PHP 확장, MySQL, Composer를 설치한다.
3. 배포 전용 사용자와 애플리케이션 디렉터리를 만든다.
4. 방화벽에서 HTTP(80)와 HTTPS(443)만 외부에 연다. MySQL(3306)과 PHP-FPM 포트는 외부에 공개하지 않는다.

### 10.2 소스 배포와 환경 설정

```bash
sudo mkdir -p /var/www/varius
sudo chown -R deploy:www-data /var/www/varius
cd /var/www/varius
git clone https://github.com/pushwing/varius.git .
cd Pagus
composer install --no-dev --optimize-autoloader
cp .env.example .env
php spark key:generate
```

`.env`에는 운영 도메인, 운영 DB 접속 정보, Kakao 키, 운영자 계정 값을 입력한다. `.env`는 Git 저장소와 Nginx document root에 두지 않는다.

```dotenv
CI_ENVIRONMENT = production
app.baseURL = 'https://your-domain.example/'
database.default.hostname = localhost
database.default.database = pagus
database.default.username = pagus
database.default.password = 운영_DB_비밀번호
database.default.DBDriver = MySQLi
database.default.port = 3306
PAGUS_ADMIN_EMAIL = admin@your-domain.example
PAGUS_ADMIN_PASSWORD = 운영자_초기_비밀번호_12자_이상
kakaolocal.apiKey = 운영_REST_API_키
kakaomaps.jsKey = 운영_JavaScript_키
```

### 10.3 Nginx 설정 예시

`/etc/nginx/sites-available/pagus`에 다음과 같이 `Pagus/public/`을 root로 지정한다.

```nginx
server {
    listen 80;
    server_name your-domain.example;
    root /var/www/varius/Pagus/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    location ~ /\. {
        deny all;
    }
}
```

PHP-FPM 소켓 경로는 서버에 설치된 PHP 버전에 맞춘다.

```bash
sudo ln -s /etc/nginx/sites-available/pagus /etc/nginx/sites-enabled/pagus
sudo nginx -t
sudo systemctl reload nginx
```

그 다음 인증서를 발급하고 HTTP에서 HTTPS로 리다이렉트한다. 인증서 발급 도구와 운영 방식은 서버 배포 환경에 맞춘다.

### 10.4 권한과 초기화

소스 전체를 웹 서버 쓰기 권한으로 만들지 말고, 애플리케이션 실행에 필요한 디렉터리만 허용한다.

```bash
cd /var/www/varius/Pagus
mkdir -p writable/session writable/uploads/restaurants
sudo chown -R deploy:www-data writable
sudo chmod -R u=rwX,g=rwX,o= writable

php spark migrate:status
php spark migrate
php spark db:seed DatabaseSeeder
```

초기화 명령은 운영 DB 백업과 접속 정보를 확인한 뒤 한 번 실행한다. 배포 자동화에서는 migration을 동시에 여러 번 실행하지 않도록 잠금·단일 배포 작업을 사용한다.

### 10.5 VPS 배포 후 확인

- `curl -I https://your-domain.example/`가 정상 HTTP 응답을 반환하는지 확인한다.
- Nginx·PHP-FPM·애플리케이션 로그에 오류가 없는지 확인한다.
- 공개 지도, Kakao 키, 운영자 로그인, 사진 업로드, 후기와 문의를 확인한다.
- `.env`, `app/`, `writable/`, `vendor/`가 HTTP로 직접 다운로드되지 않는지 확인한다.
- 운영자 계정의 초기 비밀번호를 안전하게 관리하고 재사용하지 않는다.

## 11. 운영 중 업데이트

새 소스를 배포할 때는 백업 후 다음 순서로 진행한다.

```bash
cd /배포된/varius/Pagus
git pull --ff-only
composer install --no-dev --optimize-autoloader
php spark migrate:status
php spark migrate
```

시딩은 기존 계정과 데이터를 갱신하지 않으므로 매 배포마다 실행하지 않는다. 새 seed가 필요한 경우 변경 내용을 확인한 뒤 별도로 실행한다.

## 12. 문제 해결

- `Class ... not found`: Pagus 디렉터리에서 `composer install` 또는 `composer dump-autoload --no-interaction`을 실행한다.
- `php spark`를 찾지 못함: `pwd`로 현재 경로가 `composer.json`과 `spark`가 있는 Pagus 디렉터리인지 확인한다.
- DB 연결 실패: MySQL 실행 상태와 `.env`의 hostname·database·username·password·port를 확인한다.
- migration 실패: `php spark migrate:status`와 애플리케이션 로그를 먼저 확인하며, `migrations` 테이블을 직접 수정하지 않는다.
- 로그인 실패: `.env`를 바꿔도 기존 동일 이메일 계정은 갱신되지 않는다는 점을 확인한다.
- 세션·사진 저장 실패: `writable/session`과 `writable/uploads/restaurants`의 존재·소유자·쓰기 권한을 확인한다.
- 지도 미표시: `kakaomaps.jsKey`, 실제 `app.baseURL` 호스트, Kakao Web 플랫폼 허용 도메인을 확인한다.
- 주소·장소 검색 실패: `kakaolocal.apiKey`, 외부 API 사용량과 서버의 HTTPS 통신 가능 여부를 확인한다.
- 404 또는 PHP 파일 다운로드: 웹 서버 document root가 `Pagus/public`인지와 rewrite 설정을 확인한다.
