# 파구스 (Pagus)

> 마을(Pagus) 속 진짜 맛집을 찾다, 파주 로컬 맛집 지도 파구스.

파구스는 파주에 있는 로컬 맛집을 지도와 검색으로 발견하고, 실제 방문 경험을 공유하는 서비스다. 광고성 나열보다 지역 주민의 정보와 검증 가능한 가게 정보를 우선한다.

## MVP 목표

- 파주 맛집을 지도에서 확인한다.
- 상호명·카테고리·동네로 맛집을 검색하고 필터링한다.
- 맛집 상세에서 주소, 영업 정보, 메뉴·가격, 특징, 지도 위치를 확인한다.
- 운영자가 맛집 정보를 등록·수정·공개 여부를 관리한다.
- 사용자가 방문 후기를 남기고, 부적절한 후기를 신고할 수 있다.

초기 버전은 회원 가입 없이 공개 조회가 가능하도록 하고, 후기 작성과 운영 기능은 인증된 사용자에게만 제공한다. 추천 점수, 광고 상품, 예약·주문 연동은 MVP 이후로 미룬다.

## 기술 스택

- Backend: PHP 8.4+, CodeIgniter 4
- Database: MySQL 8.0+
- View: CodeIgniter 4 server-rendered PHP view
- Map: 외부 지도 SDK를 사용하는 얇은 클라이언트 레이어
- 운영: 웹 서버가 `public/`만 document root로 제공

## 주요 화면과 흐름

1. `/`에서 파주 전체 또는 현재 위치 주변의 공개 맛집을 지도와 목록으로 본다.
2. 검색어·카테고리·동네 필터를 적용한다.
3. 맛집 상세(`/restaurants/{id}`)에서 기본 정보와 후기를 확인한다.
4. 로그인 사용자는 후기 작성·수정·삭제와 신고를 할 수 있다.
5. 운영자는 `/admin/restaurants`에서 맛집의 등록, 검수, 공개 상태를 관리한다.

## 핵심 데이터

- `restaurants`: 상호, 설명, 주소, 좌표, 전화번호, 영업 정보, 공개 상태
- `categories`: 한식·카페 등 분류명
- `restaurant_categories`: 맛집과 분류의 연결
- `users`: 인증 사용자와 운영자 역할
- `reviews`: 사용자 후기, 평점, 공개 상태
- `reports`: 후기 신고 사유와 처리 상태

맛집의 `latitude`와 `longitude`는 지도 표시의 핵심 값이므로 유효한 범위로 검증하고, 공개 목록 조회에는 공개 상태 조건을 반드시 적용한다. 후기 공개 여부와 신고 처리 상태도 별도로 관리해 삭제 대신 숨김·복구가 가능하도록 한다.

## 로컬 실행

```bash
composer install
cp env .env
php spark key:generate
php spark migrate
PAGUS_ADMIN_PASSWORD='12자 이상인 초기 비밀번호' php spark db:seed DatabaseSeeder
php spark serve
```

기본 접속 주소는 `http://pagus.test/`다. Caddy를 사용하지 않는 경우에만 `php spark serve`가 출력하는 개발 주소를 사용한다.

`.env`에는 최소한 다음 값을 로컬 MySQL 환경에 맞게 설정한다. 실제 비밀번호와 API 키는 커밋하지 않는다.

```dotenv
CI_ENVIRONMENT = development
app.baseURL = 'http://pagus.test/'
database.default.hostname = localhost
database.default.database = pagus
database.default.username = pagus
database.default.password =
database.default.DBDriver = MySQLi
database.default.port = 3306
PAGUS_ADMIN_EMAIL = admin@pagus.test
PAGUS_ADMIN_PASSWORD = change-this-password
```

회원가입은 제공하지 않으며, `PAGUS_ADMIN_PASSWORD`를 환경변수로 전달한 뒤 seed를 실행해 운영자 계정을 생성한다. 비밀번호는 `password_hash()`로 저장되고, seed는 기존 운영자 계정을 갱신하지 않는다.

## 검증

```bash
composer test
composer analyse
composer check
```

프로젝트에 해당 Composer 스크립트가 없으면 `php spark migrate --all` 및 `vendor/bin/phpunit` 등 실제 등록된 명령을 확인해 실행한다. 데이터베이스 테스트는 운영 DB가 아닌 별도 테스트 DB를 사용한다.

## 개발 원칙

- Controller는 입력 검증, 인증 확인, Service 호출, 응답 조립만 담당한다.
- Model은 데이터 접근을 담당하고, 공개 맛집 조회 조건은 한 곳에서 재사용한다.
- 쓰기 작업과 상태 전이는 Service에서 트랜잭션으로 처리한다.
- 사용자 입력은 검증하고 출력 시 문맥에 맞게 이스케이프한다.
- 상태 변경 요청에는 CSRF 보호를 적용한다.
- 좌표·주소·영업 정보처럼 신뢰 경계에 있는 값은 서버에서 다시 검증한다.

자세한 작업 규칙과 완료 조건은 [`AGENTS.md`](AGENTS.md)를 참고한다.
