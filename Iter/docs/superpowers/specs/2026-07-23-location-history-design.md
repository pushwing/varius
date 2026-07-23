# 위치기록(Timeline.json) 지원 설계

- 날짜: 2026-07-23
- 대상: `Iter/` — 기기 내보내기 Timeline.json 업로드 → 날짜별 위치기록 트랙 표시
- 상태: 설계 승인됨

## 목적

사진 사이의 빈 구간을 보완하기 위해, Google 지도 기기 내보내기 위치기록(Timeline.json)을
업로드받아 날짜별 이동 트랙을 저장하고 메인 지도에 겹쳐 보여준다.

## 배경 (반드시 숙지)

Google 은 2024년 말부터 위치기록(타임라인)을 기기 저장으로 전환했다. 현재(2026년) Takeout
웹에서는 위치기록을 내보낼 수 없고, 사용자는 **휴대폰 설정 → 위치 → 타임라인**에서
`Timeline.json`(단일 파일, 보통 5~30MB)을 직접 내보낸다. 구조(웹 검증 완료):

```json
{
  "semanticSegments": [
    {
      "startTime": "2024-06-25T19:00:00.000+09:00",
      "endTime": "2024-06-25T21:00:00.000+09:00",
      "timelinePath": [
        { "point": "37.5665000°, 126.9780000°", "time": "2024-06-25T20:24:00.000+09:00" }
      ]
    }
  ],
  "rawSignals": [],
  "userLocationProfile": {}
}
```

- `point` 는 도(°) 기호가 붙은 `"위도°, 경도°"` 문자열 — 파서가 기호를 제거해 float 로 변환한다.
- `time` 은 타임존 오프셋 포함 ISO8601 — 저장 시 **UTC** 로 변환한다(프로젝트 표준:
  저장은 UTC, 표시·날짜 그룹핑만 KST — `App\Support\TimeConverter` 참조).
- `semanticSegments` 에는 `timelinePath` 없이 `visit`/`activity` 만 있는 세그먼트도 섞여 있다 —
  **MVP 는 `timelinePath` 만 사용**하고 나머지는 무시한다(YAGNI).
- 레거시 Takeout `Records.json`(수백 MB 원시 포인트)은 **이번 범위에서 제외** — 후속 확장.

## 요구사항 (확정)

| 항목 | 결정 |
|------|------|
| 지원 포맷 | **기기 Timeline.json 먼저** (Records.json 은 후속) |
| 저장 전략 | **별도 테이블 + 다운샘플링** — 사진 테이블과 완전 분리, 재업로드 idempotent |
| 지도 표시 | **기존 동선에 겹침 + 토글** — 날짜 선택 시 점선 트랙 lazy 로드 |
| 업로드 UX | **/upload 페이지에 섹션 추가** — .json 직접 업로드(zip 불필요) |

## 기각한 대안

- **JsonMachine 스트리밍 파싱**: 기기 파일(5~30MB)엔 불필요한 의존성 — Records.json 지원 시 재검토.
- **브라우저 파싱 후 포인트 전송**: 업로드 계약 복잡·검증 로직 프론트 유출.
- **routes API 에 트랙 동봉**: 전체 날짜 × 트랙 포인트로 초기 페이로드 비대 — 날짜별 lazy fetch 채택.
- **일별 polyline 압축 저장**: 시간대 필터·후속 활용 확장성 상실.

## 상세 설계

### 데이터

- 새 테이블 `timeline_points`
  - `id` BIGINT PK, `user_id` INT(FK users), `lat` / `lng` (photo_locations 와 동일 타입),
    `recorded_at` DATETIME(**UTC** — photo_locations.taken_at 과 동일 규약), `created_at`
  - 유니크 `uniq_timeline_points_user_time (user_id, recorded_at)` — 재업로드 중복 방지
- 원본 Timeline.json 은 처리 완료(성공·실패 무관) 즉시 삭제 — 저장 정책 준수.

### 파서 — `TimelineHistoryParser` (순수)

- 입력: JSON 파일 경로. 출력: `list<TimelinePoint>` DTO (`lat`, `lng`, `recordedAt`(UTC 문자열)).
- `semanticSegments[].timelinePath[]` 만 순회. `point` 도 기호 문자열 파싱, `time` 은
  `DateTimeImmutable` 로 오프셋 포함 파싱 후 UTC 'Y-m-d H:i:s' 로 변환.
- 형식이 어긋난 항목(좌표 파싱 실패·시각 누락)은 조용히 건너뛴다. `semanticSegments` 자체가
  없으면 빈 배열(잘못된 파일은 서비스 레벨에서 에러 처리).
- 파일 크기 상한 **64MB** — 초과 시 파싱 전에 도메인 예외.

### 다운샘플링

- 시간순 정렬 후 순회: **직전 유지점 대비 60초 미만 && 이동 10m 미만이면 스킵**(정지 구간 압축).
- 하버사인은 기존 `GeoDistanceCalculator` 재사용. 첫 포인트는 항상 유지.

### 인제스트 — `TimelineHistoryIngestService`

- 업로드 파일 검증(.json 확장자·64MB) → 파싱 → 다운샘플링 → `TimelinePointModel::saveBatch`
  (유니크 충돌 행 스킵) → 임시 파일 삭제 → 요약 반환 `{saved, skipped, firstDate, lastDate}`.

### API·라우트

- `POST /location-history/upload` — sessionRateLimit 적용, 로그인 필수, 요약 JSON 응답.
  기존 `timeline/*`(시간표) 라우트와 충돌을 피해 `location-history` 프리픽스 사용.
- `GET /location-history/track/{date}` — 해당 KST 날짜의 `{points: [[lat,lng], ...]}` (로그인 필수).
  날짜 → UTC 범위 변환은 기존 `TimeConverter::kstDateToUtcRange` 재사용(사진 조회와 동일 방식).
- 컨트롤러 `LocationHistoryController` — thin(검증·위임·응답).

### 업로드 페이지

- `/upload` 에 "위치기록 가져오기" 섹션 추가: 내보내기 방법 안내문 + .json 파일 선택 + 업로드
  버튼 + 결과 요약 표시. 기존 zip 업로드 섹션과 시각적으로 구분.

### 지도 표시 (map.php)

- 사이드바에 "위치기록 트랙" 토글(체크박스). ON + 날짜 선택 시 그 날짜 트랙을
  `GET /location-history/track/{date}` 로 lazy fetch(+클라이언트 캐시).
- 트랙은 해당 날짜 색상의 **점선·얇은 선**(dashArray, weight 2, opacity 0.7)으로 사진
  동선(굵은 실선) 아래에 그린다. 트랙 없는 날은 조용히 무시.
- 토글 OFF·날짜 전환 시 기존 트랙 레이어 제거. 동선 재생 기능과는 독립(재생은 사진 동선만).

## 검증

- `TimelineHistoryParser`·다운샘플링 PHPUnit 단위 테스트(fixture JSON — 도 기호·타임존 변환·
  불량 항목 스킵·visit-only 세그먼트 무시 커버).
- `TimelinePointModel::saveBatch` 중복 스킵 DB 테스트.
- `composer ci` 통과. 지도 토글·트랙 렌더는 canned 라우트로 브라우저 검증.
- 실제 업로드 성공 경로는 `is_uploaded_file()` 제약으로 수동 확인 대상(기존 규칙).

## 배포

- 마이그레이션 1건 포함 — 배포 시 `php spark migrate` 를 별도 필수 단계로 안내(백필 없음).
