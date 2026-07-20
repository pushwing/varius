# Iter — 홈 화면 + Picker 흐름 UI 설계

> 대상: 로그인 후 사용자가 실제로 "사진 선택 → 적재 → 지도 확인"을 브라우저 클릭만으로 진행할 수 있는 홈 화면.
> 관련: 이슈 #14/#20(Picker API)·#21/#22(Ingest)·#23(지도), `docs/photo-gps-tracker-spec.md`.

## 1. 배경 / 문제

로그인(OAuth 콜백) 후 `/`가 CodeIgniter 4 기본 웰컴 페이지(`welcome_message.php`)로 연결되며, Picker 관련 엔드포인트(`POST /picker/sessions`, `GET /picker/sessions/status`, `POST /picker/ingest`)는 전부 JSON API뿐이라 사용자가 브라우저에서 클릭으로 다음 액션을 진행할 방법이 없다.

## 2. 목표 / 완료 조건

- 로그인 후 `/`에서 상단 메뉴(홈·지도 보기·로그아웃)와 함께, 버튼 클릭만으로 "사진 선택하기 → 적재 완료 → 지도 이동"까지 진행할 수 있다.
- 비로그인 상태에서 `/`는 서비스 소개 + Google 로그인 버튼만 있는 랜딩 페이지를 보여준다.
- 로그아웃 기능을 신설한다.
- 백엔드 API(`/picker/*`, `/routes`, `/map`)는 변경하지 않는다 — 프론트(뷰 + 클라이언트 JS)만 추가한다.
- `composer ci` 통과 + 신규 feature 테스트 + 실제 브라우저 구동 확인.

## 3. 확정된 설계 결정

| 결정 | 선택 | 이유 |
|------|------|------|
| 구현 접근 | **홈 화면 통합 + 클라이언트 폴링** (별도 `/picker` 페이지 분리 안 함) | `PickerController::status()`를 애초에 "블로킹 안 함, 단건 조회"로 설계한 이유가 정확히 클라이언트 폴링 용도. 백엔드 무변경으로 스코프 최소화 |
| 로그인 분기 | `Home::index()`가 세션 `user_id` 유무로 같은 뷰(`home.php`) 안에서 다른 조각 렌더 | 별도 컨트롤러·라우트 불필요 |
| 로그아웃 | `GET /auth/logout` — `session()->destroy()` 후 `/`로 리다이렉트 | CSRF 필터 비활성 프로젝트라 GET 링크로 충분, 폼 불필요 |
| 세션 재개 | 미지원(페이지 새로고침 시 JS 상태는 항상 `idle`로 초기화) | 서버 세션엔 `picker_session_id`가 남지만, 재개 UI는 이번 스코프 밖 — 다시 누르면 새 세션 생성(기존 컨트롤러 동작 그대로 안전) |
| 폴링 간격 | 클라이언트 고정 2초 | 서버가 `pollingConfig.pollInterval`을 클라이언트에 별도로 안 내려주므로(내부적으로 `pollUntilReady`에서만 소비) 단순 고정 간격 사용 |
| JS 테스트 | 자동화 대상 제외, 실제 브라우저 구동으로 확인 | 프로젝트에 JS 테스트 프레임워크 없음(`map.php`와 동일 기존 정책) |

## 4. 화면 흐름

### 4.1 비로그인 (`/`)
서비스 한 줄 소개 + "Google로 로그인" 버튼(`/auth/google`로 이동)만 있는 최소 랜딩.

### 4.2 로그인 시 (`/`)
상단 메뉴: `홈 · 지도 보기(/map) · 로그아웃(/auth/logout)`.

메인 영역은 클라이언트 JS 상태 머신으로 진행:

```
idle ──[버튼 클릭: "사진 선택하기"]──▶ POST /picker/sessions
                                          │
                                          ▼ pickerUri 새 탭으로 열기 + 화면 링크로도 노출(팝업 차단 대비)
                                          │ "선택을 기다리는 중..." 표시
                                       waiting ──[2초 간격]──▶ GET /picker/sessions/status
                                          │                        │
                                          │           mediaItemsSet=false → 계속 폴링(최대 150회 ≈ 5분)
                                          │                        │
                                          │           150회 초과 → 타임아웃 메시지 + "다시 시도" → idle
                                          │                        │
                                          ▼           mediaItemsSet=true
                                       ingesting ──▶ POST /picker/ingest
                                          │
                                          ▼
                                        done  →  "N장 저장됨" + "지도에서 보기" 버튼(/map 이동)
```

- 각 단계(`POST /picker/sessions`, `GET /picker/sessions/status`, `POST /picker/ingest`)에서 4xx/5xx 응답을 받으면 에러 메시지 + "다시 시도" 버튼으로 `idle` 복귀.
- 401(세션 만료 등) 응답이면 로그인 페이지로 안내.

### 4.3 로그아웃
`GET /auth/logout`: `session()->destroy()` → `/`로 리다이렉트. 이후 `/picker/*`·`/routes`·`/map`은 기존 인증 가드가 그대로 401/리다이렉트 처리(추가 구현 불필요).

## 5. 변경 파일

- 신규 `app/Views/home.php` — 비로그인 랜딩 + 로그인 시 메뉴/Picker 흐름 UI + 상태 머신 JS(inline, 빌드 도구 없음, `map.php`와 동일 스타일).
- 수정 `app/Controllers/Home.php` — `currentUserId()`(BaseController)로 로그인 여부 판별 후 뷰에 전달.
- 수정 `app/Controllers/AuthController.php` — `logout()` 액션 추가.
- 수정 `app/Config/Routes.php` — `auth/logout` 라우트 추가.
- 삭제 `app/Views/welcome_message.php` — `home.php`로 완전히 대체되어 더는 참조되지 않는 죽은 코드가 되므로 제거한다.

## 6. 테스트 계획

- `HomeControllerTest`(feature, 신규): 비로그인 시 로그인 버튼 마크업 포함 / 로그인 세션 있을 때 상단 메뉴 3항목 + Picker 버튼 마크업 포함.
- `AuthControllerTest`에 로그아웃 케이스 추가: 로그아웃 후 세션 `user_id` 제거 확인, 로그아웃 후 보호된 라우트(`/picker/sessions`) 재접근 시 401 확인.
- 클라이언트 JS 상태 머신은 자동화 테스트 제외 — 실제 브라우저로 로그인 → 사진 선택하기 클릭 → 상태 전환 → 지도 이동까지 구동 확인.
- `composer ci`(CS Fixer → PHPStan → PHPUnit) 통과 필수.

## 7. 범위 밖

- Picker 세션 재개(새로고침 후 진행 중이던 세션 이어가기).
- 로그아웃 CSRF 보호(프로젝트 전역 CSRF 필터가 비활성 상태이므로 이번 스코프에서 별도 처리 안 함).
- 여러 날짜 배치 선택(한 번에 10장 제한은 기존 정책 유지).
