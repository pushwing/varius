# 파구스 (Pagus)

> 마을(Pagus) 속 진짜 맛집을 찾다, 파주 로컬 맛집 지도 파구스.

파구스는 파주에 있는 로컬 맛집을 지도와 검색으로 발견하고, 실제 방문 경험을 공유하는 서비스다. 광고성 나열보다 지역 주민의 정보와 검증 가능한 가게 정보를 우선한다.

## 현재 구현 범위

현재 공개 맛집 탐색부터 운영자 관리까지의 MVP 핵심 흐름이 구현되어 있다.

- 공개 화면(`/`)에서 공개된 맛집만 목록·카카오 지도·마커로 조회한다.
- 상호명, 주소, 카테고리, 태그를 검색하고 카테고리·정렬·페이지네이션을 적용한다.
- 맛집 상세(`/restaurants/{id}`)에서 소개, 메뉴, 영업 정보, 연락처, 홈페이지, 좌표, 태그, 사진, 후기를 확인한다.
- 상세 화면에서 카카오 길찾기 링크와 장소 공유를 제공한다.
- 로그인 없이 닉네임·별점·내용·작성 비밀번호로 후기를 등록한다. 작성 비밀번호로 본인 후기만 수정·삭제할 수 있다.
- 후기 신고, 동일 신고자 중복 방지, 신고자 일일 제한, 신고 누적 시 자동 숨김을 적용한다.
- 공개 화면의 문의 다이얼로그에서 문의를 접수하고 운영자 화면에서 문의 상태를 관리한다.
- 운영자는 맛집·카테고리 등록/수정, 공개 상태 전환, 사진 업로드·숨김·삭제, 후기 공개 상태 관리가 가능하다.
- 운영자 맛집 입력 시 카카오 주소 검색과 참고 데이터 검색으로 주소·좌표 등의 입력을 보조한다.

추천 점수, 광고 상품, 예약·주문 연동, 일반 회원가입은 현재 범위에 포함하지 않는다.

## 기술 스택

- Backend: PHP 8.2+, CodeIgniter 4.6+
- Database: MySQL 8.0+
- View: CodeIgniter 4 server-rendered PHP view
- Map·주소 검색: Kakao Maps JavaScript SDK, Kakao Local API
- AI 카테고리 추천: Groq API(관리자 맛집 등록·수정 화면)
- 운영: 웹 서버가 `public/`만 document root로 제공

## 주요 경로

| 경로 | 기능 | 접근 |
| --- | --- | --- |
| `/` | 공개 맛집 목록·검색·카카오 지도·문의 | 공개 |
| `/restaurants/{id}` | 맛집 상세·사진·후기·신고·공유 | 공개 |
| `/login` | 운영자 로그인 | 공개 |
| `/admin` | 맛집 목록·검색·등록·수정·공개 상태 | 운영자 |
| `/admin/categories` | 카테고리 등록·수정·활성/숨김 | 운영자 |
| `/admin/restaurants/{id}/photos` | 사진 업로드·공개/숨김·삭제 | 운영자 |
| `/admin/reviews` | 후기 공개/숨김 관리 | 운영자 |
| `/admin/inquiries` | 문의 조회 및 처리 상태 관리 | 운영자 |

## 핵심 데이터

- `restaurants`: 상호, 설명, 주소, 좌표, 전화번호, 영업 정보, 공개 상태
- `categories`: 한식·카페 등 분류명
- `restaurant_categories`: 맛집과 분류의 연결
- `users`: 인증 사용자와 운영자 역할
- `restaurant_reviews`: 닉네임 후기, 평점, 작성 비밀번호 해시, 공개 상태, 신고 누적 수
- `restaurant_review_reports`: 후기 신고 사유와 신고자 해시
- `restaurant_photos`: 맛집 사진 메타데이터와 공개 상태
- `inquiries`: 공개 문의와 처리 상태

맛집의 `latitude`와 `longitude`는 지도 표시의 핵심 값이므로 `latitude -90..90`, `longitude -180..180` 범위로 서버 검증한다. 공개 목록·상세·사진·후기 조회에는 각각 공개 상태 조건을 적용한다. 후기와 사진은 삭제 또는 숨김 상태를 별도로 관리해 운영자가 복구할 수 있도록 한다.

## 로컬 실행

```bash
composer install
cp env .env
php spark key:generate
php spark migrate
PAGUS_ADMIN_PASSWORD='12자 이상인 초기 비밀번호' php spark db:seed DatabaseSeeder
php spark serve
```

실행 주소는 설치 환경의 자기 도메인 또는 테스트 서버 주소를 사용한다. 설치·웹호스팅·VPS 배포 절차는 [`SETUP.md`](SETUP.md)를 참고한다.

`.env`에는 최소한 다음 값을 로컬 MySQL 환경에 맞게 설정한다. 실제 비밀번호와 API 키는 커밋하지 않는다.

```dotenv
CI_ENVIRONMENT = development
app.baseURL = 'http://localhost:8080/'
database.default.hostname = localhost
database.default.database = pagus
database.default.username = pagus
database.default.password =
database.default.DBDriver = MySQLi
database.default.port = 3306
kakaolocal.apiKey = 발급받은_Kakao_REST_API_키
kakaomaps.jsKey = 발급받은_Kakao_JavaScript_키
groq.apiKey = 발급받은_Groq_API_키
PAGUS_ADMIN_EMAIL = admin@example.com
PAGUS_ADMIN_PASSWORD = change-this-password
```

일반 회원가입은 제공하지 않는다. `PAGUS_ADMIN_PASSWORD`를 환경변수로 전달한 뒤 seed를 실행해 운영자 계정을 최초 생성한다. 운영자 비밀번호와 후기 작성 비밀번호는 `password_hash()`로 저장하며, seed는 기존 운영자 계정을 갱신하지 않는다. 운영자 경로는 세션 기반 `AdminFilter`로 보호한다.

## 검증

```bash
composer test
composer analyse
composer check
```

세 명령은 각각 PHPUnit 테스트, PHPStan 정적 분석, PHP-CS-Fixer dry-run 및 `git diff --check`를 실행한다. 데이터베이스 테스트는 운영 DB가 아닌 별도 테스트 DB를 사용한다.

외부 연동과 화면은 별도로 확인한다.

- 카카오 지도 SDK가 정상 로드되는지와 공개 맛집 마커·상세 링크를 실제 서비스 도메인에서 확인한다.
- 운영자 주소 검색·참고 데이터 검색은 Kakao API 키가 설정된 환경에서 실제 호출한다.
- 운영자 상호 입력 후 AI 카테고리 추천은 Groq API 키가 설정된 환경에서 실제 호출한다. 실패하면 카테고리를 직접 선택한다.
- API 키가 없거나 외부 서비스가 실패하면 주소·좌표 직접 입력으로 이어지는 오류 처리를 사용한다.

## 개발 원칙

- Controller는 입력 검증, 인증 확인, Service 호출, 응답 조립만 담당한다.
- Model은 데이터 접근을 담당하고, 공개 맛집 조회 조건은 한 곳에서 재사용한다.
- 쓰기 작업과 상태 전이는 Service에서 트랜잭션으로 처리한다.
- 사용자 입력은 검증하고 출력 시 문맥에 맞게 이스케이프한다.
- 상태 변경 요청에는 CSRF 보호를 적용한다.
- 좌표·주소·영업 정보처럼 신뢰 경계에 있는 값은 서버에서 다시 검증한다.

자세한 작업 규칙과 완료 조건은 [`AGENTS.md`](AGENTS.md)를 참고한다.

처음 설치하거나 로컬 환경을 구성할 때는 [`SETUP.md`](SETUP.md)를 참고한다.
