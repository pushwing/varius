# 이슈 34 — 외부 참고 데이터 조회 연동(카카오 로컬)

- 이슈: [#34 파구스 외부 참고 데이터 조회 연동 구현](https://github.com/pushwing/varius/issues/34)
- 작성일: 2026-08-27

## 제공자 선정

식신은 공식 개발자 API를 확인할 수 없어 이슈의 "식신 등 허용된 제공자" 조건에 따라 대체 제공자를 검토했다.
기존 `GeocodingService`가 이미 외부 좌표 조회 패턴을 갖추고 있어, 같은 구조로 붙일 수 있고 공식 개발자
문서·API 키 발급이 존재하는 **카카오 로컬(장소 검색) API**를 채택했다.

- 엔드포인트: `GET https://dapi.kakao.com/v2/local/search/keyword.json`
- 인증: REST API 키를 `Authorization: KakaoAK {키}` 헤더로 전달
- 발급: [카카오 개발자 콘솔](https://developers.kakao.com) 애플리케이션 생성 후 REST API 키 확인

## 호출 제한과 이용 조건

- 카카오 로컬 API는 앱 단위 일일/초당 호출 한도가 있다(콘솔의 애플리케이션 > 이용현황에서 실제 한도를 확인한다).
- 검색 결과를 화면에 노출할 때는 카카오 제공 표시(저작권 표시)를 함께 노출한다 — 운영자 화면 참고 데이터 검색 위젯에
  "검색 결과 제공: 카카오" 문구로 반영했다.
- 상업적 재판매·대량 수집(크롤링) 목적 사용은 금지되며, 이 기능은 운영자가 맛집 1건을 등록할 때 참고용으로
  단건 조회하는 용도로만 사용한다.

## 설계 요약

- `Config\KakaoLocal` — 엔드포인트, API 키(`kakaolocal.apiKey` 환경변수), timeout, 결과 개수 제한을 담는다.
  API 키는 `.env`에서만 관리하고 코드·로그에 남기지 않는다.
- `App\Services\KakaoLocalReferenceService::search()` — 키워드로 카카오 로컬을 조회해
  `name`·`address`·`phone`·`category`·`latitude`·`longitude`로 정규화한다. 다음 경우 모두 `null`을 반환해
  맛집 등록을 막지 않는다: API 키 미설정, HTTP 오류·타임아웃(예외), 200이 아닌 응답.
- `AdminController::searchReference()` — `GET /admin/restaurants/search-reference?q=` (운영자 인증 필요).
  검색어 2~100자 검증, 서비스가 `null`을 반환하면 503과 함께 "직접 입력하세요" 안내를 반환한다.
- 운영자 등록 폼(`admin/index.php`)에 검색 위젯을 추가했다. 결과를 선택하면 상호·주소·연락처·좌표 입력칸만
  채워지며, 공개 여부(`is_published`)는 기존 흐름대로 운영자가 별도로 전환한다 — 외부 데이터가 공개 여부나
  권한 판단을 대신하지 않는다.

## 테스트

`tests/Unit/KakaoLocalReferenceServiceTest.php`에서 카카오 API를 모킹해 재현 가능하게 검증한다.

- 응답 정규화(주소 우선순위, 좌표 범위, 누락 필드 기본값)
- API 키 미설정 시 `null` 반환
- HTTP 클라이언트 예외·비정상 상태코드 시 `null` 반환(등록 차단 없음)
- 모킹된 클라이언트로 정상 응답을 정규화된 배열로 반환하는지 확인
