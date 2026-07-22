# 여행 그룹핑(Trip Grouping) 설계

## 배경

현재 Iter는 날짜 단위로만 동선·시간표를 보여준다(`/map` 사이드바, `/timeline/{date}`). 며칠에
걸친 여행도 날짜마다 흩어져 표시되어, "이번 여행 전체"를 한눈에 보거나 공유할 방법이 없다.

이 설계는 연속된 날짜들을 하나의 **여행(Trip)**으로 묶어 커버 사진·기간·사진 수를 보여주는
상위 뷰를 추가한다. 여행 경계는 자동으로 제안하되 사용자가 제목·기간·커버를 직접 조정할 수
있는 **하이브리드** 방식으로 만든다.

## 범위

- **포함**: 여행 자동 제안(3일 공백 규칙), 여행 저장·수정·삭제, "내 여행" 목록·상세 화면, 여행
  단위 SNS 공유(비로그인 공개 페이지).
- **제외**: 이동거리·방문 지역 수 등 부가 통계(기간·사진 수만 표시), 비연속 날짜를 한 여행으로
  묶는 기능(멤버십은 항상 `[start_date, end_date]` 범위), 여행 상세 화면 내 날짜별 전체 시간표
  임베드(요약 그리드만 — 상세 시간표는 기존 `/map?date=` 링크로 연결).
- 기존 `day_notes`·`time_notes`·`photo_locations`·`share_links`는 변경하지 않는다. 여행은 그
  위에 얹히는 순수 그룹핑 레이어다.

## 데이터 모델

### `trips` 테이블 (신규)

| 컬럼 | 타입 | 설명 |
|------|------|------|
| `id` | BIGINT PK | |
| `user_id` | BIGINT FK→users | |
| `title` | VARCHAR(100) | |
| `body` | TEXT NULL | |
| `start_date` | DATE | |
| `end_date` | DATE | |
| `cover_photo_id` | BIGINT NULL FK→photo_locations | 비어 있으면 화면에서 "여행 첫날 첫 사진"으로 즉석 대체(계산값, 저장 안 함) |
| `created_at`, `updated_at` | DATETIME | |

- 유니크 제약 없음(범위라 (user, date) 단순 유니크 불가) — **애플리케이션 레벨 겹침 검사**로
  대신한다: 같은 사용자의 기존 Trip과 기간이 겹치면 저장·수정 시 422.
- 여행에 속한 날짜는 별도 멤버십 테이블 없이 **"그 범위 안에 사진이 있는 날짜들"**로 계산한다.

`TripModel`(`app/Models/TripModel.php`)은 순수 데이터 접근만 담당한다(검증은 다른 컨트롤러들과
동일하게 `TripController`가 담당 — `TimelineController::saveDayNote`가 글자 수 검증을 컨트롤러에서
직접 하는 것과 같은 패턴):
- `overlaps(userId, startDate, endDate, excludeId = null): bool` — 겹침 검사(아래 검증 규칙의
  공식 사용). `TripController`가 create/update 전에 호출해 422 여부를 판단한다.
- `create(userId, title, body, startDate, endDate, coverPhotoId): int` — 단순 삽입, 겹침 검사는
  호출측(`overlaps()`)이 이미 통과시킨 뒤 호출.
- `findByUserOrdered(userId): list<array>` — 최신 시작일순.
- `findOwned(id, userId): ?array`
- `update(id, userId, fields): bool` — 단순 갱신(겹침 검사는 호출측 책임).
- `delete(id, userId): bool`

### `trip_share_links` 테이블 (신규)

기존 `share_links`(날짜 단위)와 병렬 구조, 여행 단위 버전.

| 컬럼 | 타입 | 설명 |
|------|------|------|
| `id` | BIGINT PK | |
| `trip_id` | BIGINT FK→trips (UNIQUE) | 여행당 토큰 1개 |
| `token` | CHAR(32) UNIQUE | |
| `created_at` | DATETIME | |

`TripShareLinkModel`:
- `createOrGet(tripId): string` — 이미 있으면 재사용(이미 퍼진 링크가 깨지지 않도록).
- `findByToken(token): ?array{trip_id: int}`

## 자동 제안 로직

`TripSuggestionService::suggest(userId): list<TripSuggestion>` — **아무것도 저장하지 않는 순수
계산.** "내 여행" 페이지를 열 때마다 새로 계산되고, 사용자가 "저장"을 눌러야 비로소
`TripModel::create()`가 호출된다.

1. `PhotoLocationModel::findByUserOrdered($userId)`로 전체 좌표(taken_at UTC 오름차순) 조회(기존
   메서드 재사용).
2. 각 행을 `TimeConverter::utcToKst()`로 KST 날짜로 변환, 중복 제거한 날짜 오름차순 목록 생성
   (날짜별 사진 수도 함께 카운트).
3. `TripModel::findByUserOrdered($userId)`로 이미 저장된 Trip들의 `[start_date, end_date]`
   범위를 가져와, 그 범위에 포함되는 날짜는 후보에서 제외.
4. 남은 날짜를 순회하며 **직전 날짜와의 간격이 3일 미만이면 같은 그룹, 3일 이상이면 새 그룹**
   으로 묶는다(단일 날짜만 있는 그룹도 유효한 제안).
5. 그룹마다 다음을 반환:

```
TripSuggestion {
    start_date: string
    end_date: string
    photo_count: int
    suggested_title: string   // "3월 15일~17일 여행" / 단일 날짜면 "3월 15일 여행"
    first_photo_id: int
}
```

## 화면 흐름

### `GET /trips` — "내 여행" 목록

상단 네비게이션에 "내 여행" 메뉴를 추가한다(`app/Views/partials/nav.php`). 페이지는 껍데기만
서버 렌더링하고(기존 `map.php` 패턴), 로드 후 `GET /trips/data`(JSON)를 fetch해 클라이언트에서
그린다.

- **저장된 여행**: 카드 그리드(커버 사진·제목·기간·사진 수). 클릭 시 `GET /trips/{id}`로 이동.
- **제안 여행**: 저장 안 된 그룹을 카드로 보여주되, "저장" 버튼 클릭 시 제목·기간을 확인·수정할
  수 있는 폼이 인라인으로 펼쳐진다 → 확정하면 `POST /trips` 호출 후 목록에서 "저장된 여행"으로
  이동.

### `GET /trips/{id}` — 상세/편집

껍데기 렌더 후 `GET /trips/{id}/data`(JSON: 트립 정보 + 포함된 날짜별
`{date, photo_count, first_thumbnail}`)를 fetch.

- 제목·설명 인라인 편집(`day_note`와 동일한 저장 버튼 패턴) → `POST /trips/{id}/update`.
- 기간(시작일·종료일) 수정 — 겹침 재검사(자기 자신 제외).
- 커버 사진 선택 — **포함된 각 날짜의 대표(첫) 사진만** 후보로 제시(사진 전체를 긁어오지 않아
  가벼움).
- 포함된 날짜 목록의 각 항목에 **"이 날 시간표 보기" 링크 → `/map?date=YYYY-MM-DD`**로 연결
  (기존 업로드→지도 포커스 기능 재사용).
- 삭제 버튼 → `POST /trips/{id}/delete`. Trip 레코드만 지운다(사진·day_note·time_note는 그대로
  남고, 그 날짜들은 다음에 "내 여행"을 열 때 다시 제안 후보로 돌아온다).
- 우측 상단 "🔗 공유" 버튼 — 이슈 #19와 동일한 SNS 공유 메뉴(X·페이스북·카카오톡·인스타그램·
  링크 복사). `POST /trips/{id}/share`로 토큰 URL을 받아온다. 이 페이지는 `map.php`와 별개 뷰라
  공유 메뉴 JS는 소규모로 복제한다(프로젝트가 페이지마다 독립 인라인 스크립트 구조라 공용
  모듈화보다 결이 맞음).

## API

```
GET  /trips              — 페이지 껍데기
GET  /trips/data          — { trips: [...], suggestions: [...] }
POST /trips               — 생성 (title, body, start_date, end_date, cover_photo_id?)
GET  /trips/{id}          — 상세 페이지 껍데기
GET  /trips/{id}/data     — { trip, days: [{date, photo_count, first_thumbnail}] }
POST /trips/{id}/update   — 수정(동일 필드, 자기 자신 제외 겹침 검사)
POST /trips/{id}/delete   — 삭제
POST /trips/{id}/share    — 공유 링크 생성/재사용 → { url }

GET  /t/{token}                    — 공개 여행 요약 페이지(비로그인)
GET  /t/{token}/thumbnails/{id}    — 공개 썸네일(비로그인, 여행 범위·소유자 스코프)
```

쓰기 엔드포인트(`POST /trips*`, `/trips/{id}/*`)에는 `sessionRateLimit:trips,120` 필터를 적용한다
(기존 `notes` 버킷과 동일 수준).

## 공유(공개 페이지)

- `TripController::share($id)` — 로그인 필요, 소유자 확인 후 `TripShareLinkModel::createOrGet`로
  토큰 발급, `{ url: site_url('t/' . $token) }` 반환.
- `TripShareController::show($token)` — 토큰 조회 실패 시 404. 여행 제목·설명·커버 + **포함된
  날짜별 섹션**(날짜 라벨 + 그 날 사진 썸네일 그리드, 최대 6장)을 렌더링.
  - `PhotoLocationModel::findByUserBetween`으로 여행 전체 범위(시작일 KST 00:00 ~ 종료일 KST
    23:59:59, UTC 변환)를 한 번에 조회한 뒤 `TimeConverter::utcToKst()`로 KST 날짜별 그룹핑
    (`RouteVisualizationService`와 유사한 패턴이나 클러스터링 없이 날짜·썸네일 목록만 필요).
  - 시간대별 세부 정보(POI·메모)는 포함하지 않는다(요약만).
- `TripShareController::thumbnail($token, $id)` — 토큰 조회 → 소유자 확인
  (`PhotoLocationModel::findOwned`) → 사진의 KST 날짜가 **여행 범위 안**인지 검사(기존
  `ShareController::thumbnail`의 단일 날짜 비교를 범위 비교로 확장) → 404 또는 서빙.

## 검증 규칙

- 제목 ≤100자, 설명 ≤2000자(`day_note`와 동일 상한).
- `start_date`/`end_date`: `YYYY-MM-DD` 형식·실존 날짜, `end_date >= start_date`.
- **여행 기간 상한 60일** — 겹침 스캔·공유 페이지 렌더링이 무한정 커지는 것을 방지하는 방어적
  상한. 초과 시 422.
- 겹침 검사: 같은 사용자의 기존 여행 `[e.start_date, e.end_date]`와 새/수정 대상
  `[n.start_date, n.end_date]`이 `NOT (n.end_date < e.start_date OR n.start_date > e.end_date)`를
  만족하면(표준 구간 겹침 공식) 422("겹치는 여행이 있습니다"). 수정 시엔 자기 자신 제외.
- `cover_photo_id` 지정 시 소유자 확인(`PhotoLocationModel::findOwned`) + 그 사진의 KST 날짜가
  `[start_date, end_date]` 안인지 검사, 아니면 422.
- 모든 조회·수정·삭제는 소유자 검사(다른 사용자 소유면 404), 쓰기 엔드포인트는 `trips`
  레이트리밋 버킷(120회/시간).
- 공개(`/t/{token}`) 엔드포인트는 로그인 불필요. 범위·소유자 밖 사진은 존재를 노출하지 않기
  위해 404로 응답한다(기존 `ShareController`와 동일 원칙).

## 테스트 전략

프로젝트 기존 컨벤션 그대로: 모델→`tests/database/`, 순수 로직→`tests/unit/`, 컨트롤러→
`tests/feature/`. 새 기능이므로 TDD(RED→GREEN)로 구현한다.

- `tests/database/TripModelTest.php` — 생성, 겹침 거부(create/update 양쪽), 조회, 수정, 삭제.
- `tests/unit/TripSuggestionServiceTest.php` — 3일 공백 그룹핑, 이미 저장된 여행 범위 제외,
  UTC/KST 날짜 경계(자정 근처 촬영 시각) 케이스, 사진 없을 때 빈 목록.
- `tests/database/TripShareLinkModelTest.php` — 토큰 재사용, `findByToken` 성공/실패.
- `tests/feature/TripControllerTest.php` — 인증 가드, CRUD 성공 경로, 겹침 422, 커버 검증
  (소유자 아님/범위 밖), `/trips/data`·`/trips/{id}/data` 응답 형태, 삭제 후 재제안 확인.
- `tests/feature/TripShareControllerTest.php` — 비로그인 열람, 날짜 범위·소유자 스코프 밖 사진
  404, 알 수 없는 토큰 404.

## 열린 질문 / 후속 과제 (이번 범위 아님)

- 여행 상세에서 시간표 전체(POI·메모 포함)를 인라인으로 보고 싶다는 요구가 나오면, 요약 그리드
  옆에 "전체 보기" 토글을 추가하는 식으로 확장 가능(현재는 `/map?date=` 링크로 우회).
- 이동거리·방문 지역 수 같은 부가 통계는 이번엔 제외했지만, `GeoDistanceCalculator`를 그대로
  재사용할 수 있어 추후 추가가 어렵지 않다.
