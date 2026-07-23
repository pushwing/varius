# 여행 상세 인라인 시간표 — 사진 확대 뷰어 설계

## 배경

`docs/superpowers/specs/2026-07-22-trip-detail-inline-timeline-design.md`에서 여행 상세
페이지(`/trips/{id}`)의 인라인 시간표를 만들 때, "사진 확대 뷰어(좌우 이동·회전·삭제)는
지도 페이지 고유 기능으로 남긴다"고 명시적으로 범위에서 제외했다. 사용자 요청으로 이 결정을
재검토해, 여행 상세의 인라인 시간표에서도 사진을 클릭해 크게 볼 수 있도록 한다.

지도 페이지(`app/Views/map.php`)에는 이미 완성된 사진 확대 뷰어(`#photo-viewer`)가 있다 —
확대·좌우 이동(키보드 `←`/`→` 포함)·90도 회전·삭제를 지원하며, 기존
`POST /photos/{id}/rotate`·`POST /photos/{id}/delete`(`PhotoController`, 사용자 소유권만
검증하고 어느 페이지에서 호출하든 동일하게 동작) API를 사용한다.

## 범위

- **포함**: `app/Views/trip-detail.php`의 인라인 시간표에서 사진 썸네일을 클릭하면 지도
  페이지와 동일한 사진 확대 뷰어가 열리도록 한다(확대·좌우 이동·회전·삭제 전부 포함).
- **제외**: 새 백엔드 코드(기존 `PhotoController`의 회전·삭제 API를 그대로 재사용). 뷰어
  컴포넌트를 공유 JS 파일로 분리하는 리팩터링(이 프로젝트는 번들러 없이 각 뷰가 독립
  인라인 스크립트를 갖는 관례라, 이번 범위에서는 `map.php`의 뷰어 코드를 `trip-detail.php`에
  그대로 복제한다 — 기존 시간표 로직도 두 파일이 각자 독립 구현을 갖는 것과 동일한 패턴).

## 아키텍처

### `app/Controllers/TripController.php::show()`

`view('trip-detail', [...])` 호출에 전달하는 배열에 `'photosUrl' => site_url('photos'),`를
추가한다(`RouteController`가 `map.php`에 이미 동일하게 전달하고 있는 패턴을 그대로 따름).

### `app/Views/trip-detail.php` — 마크업·CSS 이식

`map.php`의 `#photo-viewer` 마크업(닫기 버튼, 이전/다음 버튼, `<img>`, 캡션, 회전×2·삭제
컨트롤 버튼)과 관련 CSS 전체를 그대로 복제한다. `trip-detail.php`에는 `map.php`의
`#photo-layer-grid`(클러스터 팝업의 "더보기" 그리드)에 해당하는 요소가 없으므로, JS의
`openViewerFrom()`이 대상 셀렉터를 분기할 필요 없이 `.timeline-photos img` 하나로 고정한다.

### JS — 뷰어 로직

`map.php`의 뷰어 관련 함수(`openViewerFrom`, `showViewerAt`, `closeViewer`, prev/next 버튼
핸들러, `Escape`/`ArrowLeft`/`ArrowRight` 키보드 핸들러, `rotateViewerPhoto`, 삭제 핸들러)를
`trip-detail.php`의 기존 IIFE 스코프 안으로 그대로 옮긴다. 사진 클릭은 기존
`buildDayTimelineSlot()`이 만드는 `.timeline-photos img` 썸네일에 이벤트 위임(`document.body`
클릭 리스너)으로 연결한다.

### 좌우 이동 범위

아코디언 구조상 한 번에 하나의 날짜 패널만 열려 있으므로,
`document.querySelectorAll('.timeline-photos img')`는 자연히 "현재 열린 날짜의 모든 시간대에
걸친 사진"을 시간순으로 반환한다(`map.php`의 시간표 레이어와 동일 동작 — 슬롯 경계를 넘어
하루 전체를 순서대로 넘겨봄).

### 회전 시 정합성

서버가 같은 파일 경로를 덮어쓰므로 캐시버스터(`?v={timestamp}`)만 붙여 뷰어와 썸네일
`<img>`의 `src`를 갱신한다(`map.php`와 동일). `timelineCache`는 이미지 URL 문자열 자체를
보관하지 않으므로(썸네일 URL은 `/thumbnails/{id}` 고정 경로) 별도 캐시 무효화가 필요 없다.

### 삭제 시 정합성

1. 뷰어를 닫는다.
2. 현재 열린 날짜 패널을 접는다(`toggleDayTimeline()`과 동일하게 `openTimelineDate = null`,
   해당 토글 버튼 텍스트를 "시간표 보기"로 되돌림).
3. 그 날짜의 `timelineCache[date]` 항목을 삭제해 다음에 열 때 재조회되도록 한다(삭제된 사진이
   캐시로 인해 다시 보이는 것을 방지).
4. 여행 상세 상단의 이동거리·방문 지점 통계와 "포함된 날짜" 목록(사진 수)은 좌표·사진 수가
   바뀌므로, `fetch(tripUrl + '/data')`를 다시 호출해 `render(data)`로 페이지 전체를
   최신 상태로 갱신한다.

## 데이터 흐름

```
사진 썸네일 클릭(.timeline-photos img)
  → openViewerFrom(imgEl) — 같은 날짜 패널 안의 모든 .timeline-photos img 를 순서대로 수집
  → showViewerAt(index) — 뷰어에 원본 크기로 표시, 캡션(촬영 시각), prev/next 버튼 노출 여부 결정

회전 버튼 클릭 → POST /photos/{id}/rotate → 캐시버스터로 뷰어·썸네일 src 갱신

삭제 버튼 클릭(확인 대화상자) → POST /photos/{id}/delete
  → 뷰어 닫기 → 날짜 패널 접기 → timelineCache[date] 제거 → GET /trips/{id}/data 재조회
```

## 엣지 케이스

| 상황 | 처리 |
|------|------|
| 날짜 패널의 첫 번째 사진에서 "이전" 클릭 | `showViewerAt()`이 범위 밖 인덱스를 무시(map.php와 동일) — prev 버튼이 애초에 `hidden` |
| 마지막 사진에서 "다음" 클릭 | 동일하게 next 버튼이 `hidden` |
| 삭제 후 그 날짜에 사진이 0장 남음 | `fetch(.../data)` 재조회로 "포함된 날짜" 목록에서 자연히 사라지거나(그 날짜에 사진이 하나도 없으면 목록에서 빠짐 — 기존 `TripSummaryService` 동작 그대로), 날짜 패널이 이미 접혀 있으므로 빈 패널이 잠깐 보이는 문제 없음 |
| 뷰어가 열린 상태에서 배경(어두운 영역) 클릭 | `map.php`와 동일한 "mousedown이 배경에서 시작된 경우만 닫기" 로직으로 텍스트 드래그 중 실수로 닫히는 것 방지 |

## 테스트 전략

이 변경은 순수 프론트엔드(JS 이벤트·DOM 조작)이며 백엔드 코드 변경이 없다(`PhotoController`,
`PhotoManagementService`는 이미 완전히 테스트된 상태로 그대로 재사용). `TripController::show()`에
`photosUrl` 필드 하나가 추가되므로, 기존 `TripControllerTest::testShowRendersPageShell`류
테스트에 `data-photos-url` 속성 존재 확인을 추가한다. 실제 확대·좌우이동·회전·삭제 동작은
자동화 테스트로 검증할 수 없어(브라우저 인터랙션) 실제 브라우저로 수동 확인한다.
