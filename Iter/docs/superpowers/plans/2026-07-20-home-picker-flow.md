# 홈 화면 + Picker 흐름 UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 로그인 후 사용자가 브라우저 클릭만으로 "사진 선택 → 적재 → 지도 확인"을 진행할 수 있는 홈 화면과 로그아웃 기능을 추가한다.

**Architecture:** `Home::index()`가 로그인 세션 유무로 분기해 단일 뷰(`home.php`) 안에서 랜딩(비로그인) 또는 상단 메뉴 + Picker 흐름 UI(로그인)를 렌더링한다. 백엔드 API(`/picker/*`, `/routes`, `/map`)는 무변경 — 프론트(뷰 + inline JS)만 추가한다. 클라이언트 JS가 기존 `/picker/sessions` → `/picker/sessions/status`(폴링) → `/picker/ingest` API를 순서대로 호출하는 상태 머신을 구현한다.

**Tech Stack:** CodeIgniter 4 View(순수 PHP 템플릿) + vanilla JS(빌드 도구 없음, `app/Views/map.php`와 동일 스타일, IIFE + `fetch`).

## Global Constraints

- 클라이언트 폴링 간격: 고정 2초.
- 폴링 최대 150회(약 5분) 초과 시 타임아웃 처리.
- 백엔드 API(`PickerController`, `RouteController`, 라우트)는 이번 작업에서 변경하지 않는다.
- CS Fixer·PHPStan 설정(`Iter/.php-cs-fixer.dist.php`, `Iter/phpstan.neon`)은 `app/Views/*`를 이미 검사 대상에서 제외한다 — 뷰 파일은 `composer ci`의 CS/PHPStan 단계 영향을 받지 않는다.
- 클라이언트 JS 상태 머신은 자동화 테스트 대상에서 제외한다(프로젝트에 JS 테스트 프레임워크 없음, `map.php`와 동일 기존 정책) — PHP 쪽에서 렌더링된 마크업(메뉴 링크·버튼 id·API URL data 속성)만 feature 테스트로 검증한다.
- 로그아웃은 CSRF 보호 없이 `GET`으로 구현한다(프로젝트 전역 CSRF 필터가 `app/Config/Filters.php`에서 비활성 상태 — `Iter/CLAUDE.md`·스펙 문서 7절 참고).
- 커밋 메시지 형식: 이모지 + Conventional Commits 접두어 + 한국어 설명(전역 git-workflow 규칙).

---

### Task 1: 로그아웃 기능

**Files:**
- Modify: `Iter/app/Controllers/AuthController.php`
- Modify: `Iter/app/Config/Routes.php`
- Test: `Iter/tests/feature/AuthControllerTest.php`

**Interfaces:**
- Produces: `AuthController::logout(): RedirectResponse` — `GET /auth/logout` 호출 시 `session()->destroy()` 후 `/`로 리다이렉트. Task 2의 `home.php` 뷰가 이 라우트를 로그아웃 링크(`href`)로 참조한다.

- [ ] **Step 1: 실패하는 테스트 작성**

`Iter/tests/feature/AuthControllerTest.php`의 `testCallbackRejectsMissingCode()` 메서드(파일 끝, 클로징 `}` 직전) 뒤에 아래 두 메서드를 추가한다:

```php
    public function testLogoutDestroysSessionAndRedirects(): void
    {
        $result = $this->withSession(['user_id' => 42])->get('auth/logout');

        $result->assertRedirect();
        $this->assertNull(session()->get('user_id'));
    }

    public function testProtectedRouteRequires401AfterLogout(): void
    {
        $this->withSession(['user_id' => 42])->get('auth/logout');

        $result = $this->post('picker/sessions');

        $result->assertStatus(401);
    }
```

- [ ] **Step 2: 테스트 실패 확인**

Run: `cd Iter && vendor/bin/phpunit tests/feature/AuthControllerTest.php`
Expected: `testLogoutDestroysSessionAndRedirects`·`testProtectedRouteRequires401AfterLogout` 두 건이 라우트 없음(404) 또는 메서드 부재로 실패.

- [ ] **Step 3: 라우트 등록**

`Iter/app/Config/Routes.php`에서 아래 줄:

```php
$routes->get('auth/google/callback', 'AuthController::callback');
```

바로 뒤에 추가:

```php
$routes->get('auth/logout', 'AuthController::logout');
```

- [ ] **Step 4: `logout()` 액션 구현**

`Iter/app/Controllers/AuthController.php`의 `callback()` 메서드가 끝나는 `}` (파일의 클래스 클로징 `}` 바로 앞) 뒤에 추가:

```php

    /**
     * 로그아웃 — 세션을 파괴하고 홈으로 리다이렉트한다(GET /auth/logout).
     */
    public function logout(): RedirectResponse
    {
        session()->destroy();

        return redirect()->to('/');
    }
```

(`RedirectResponse`는 파일 상단에 이미 `use CodeIgniter\HTTP\RedirectResponse;`로 import돼 있으므로 추가 import 불필요.)

- [ ] **Step 5: 테스트 통과 확인**

Run: `cd Iter && vendor/bin/phpunit tests/feature/AuthControllerTest.php`
Expected: `OK (5 tests, ...)` — 기존 3건 + 신규 2건 전부 통과.

- [ ] **Step 6: 커밋**

```bash
cd Iter
git add app/Controllers/AuthController.php app/Config/Routes.php tests/feature/AuthControllerTest.php
git commit -m "✨ feat: 로그아웃 기능 — GET /auth/logout"
```

---

### Task 2: 홈 화면(랜딩 + 메뉴 + Picker 흐름 UI)

**Files:**
- Modify: `Iter/app/Controllers/Home.php`
- Create: `Iter/app/Views/home.php`
- Delete: `Iter/app/Views/welcome_message.php`
- Test: `Iter/tests/feature/HomeControllerTest.php` (신규)

**Interfaces:**
- Consumes: Task 1의 `GET /auth/logout` 라우트(로그아웃 링크 href), 기존 `POST /picker/sessions`(응답 `{sessionId, pickerUri}`), `GET /picker/sessions/status`(응답 `{mediaItemsSet: bool}`), `POST /picker/ingest`(응답 `{saved: int, locations: [...]}`), `GET /map`.
- Produces: `Home::index(): string` — `GET /` 렌더링. 이후 어떤 태스크도 이 인터페이스를 소비하지 않는다(최종 사용자 화면).

- [ ] **Step 1: 실패하는 테스트 작성**

`Iter/tests/feature/HomeControllerTest.php` 신규 생성:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class HomeControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $refresh = true;
    protected $namespace = 'App';

    public function testShowsLoginButtonWhenNotLoggedIn(): void
    {
        $result = $this->get('/');

        $result->assertStatus(200);
        $body = (string) $result->getBody();
        $this->assertStringContainsString('Google로 로그인', $body);
        $this->assertStringContainsString('/auth/google', $body);
        $this->assertStringNotContainsString('id="start-picker"', $body);
    }

    public function testShowsMenuAndPickerButtonWhenLoggedIn(): void
    {
        $userId = (new UserModel())->upsertByGoogleSub('sub-home', 'home@example.com', 'Home');

        $result = $this->withSession(['user_id' => $userId])->get('/');

        $result->assertStatus(200);
        $body = (string) $result->getBody();
        $this->assertStringContainsString('지도 보기', $body);
        $this->assertStringContainsString('/map', $body);
        $this->assertStringContainsString('로그아웃', $body);
        $this->assertStringContainsString('/auth/logout', $body);
        $this->assertStringContainsString('id="start-picker"', $body);
        $this->assertStringContainsString('/picker/sessions', $body);
    }
}
```

- [ ] **Step 2: 테스트 실패 확인**

Run: `cd Iter && vendor/bin/phpunit tests/feature/HomeControllerTest.php`
Expected: 두 테스트 모두 실패(`welcome_message` 뷰엔 "Google로 로그인"·"지도 보기" 등 문자열이 없음).

- [ ] **Step 3: `Home::index()` 수정**

`Iter/app/Controllers/Home.php` 전체를 아래로 교체:

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

class Home extends BaseController
{
    public function index(): string
    {
        helper('url');

        return view('home', [
            'userId' => $this->currentUserId(),
            'loginUrl' => site_url('auth/google'),
            'logoutUrl' => site_url('auth/logout'),
            'mapUrl' => site_url('map'),
            'sessionsUrl' => site_url('picker/sessions'),
            'statusUrl' => site_url('picker/sessions/status'),
            'ingestUrl' => site_url('picker/ingest'),
        ]);
    }
}
```

- [ ] **Step 4: `home.php` 뷰 작성**

`Iter/app/Views/home.php` 신규 생성:

```php
<?php

declare(strict_types=1);

/**
 * 홈 화면 — 비로그인 시 랜딩, 로그인 시 상단 메뉴 + Picker 흐름 UI.
 *
 * @var int|null $userId    로그인 사용자 id(비로그인 시 null)
 * @var string   $loginUrl
 * @var string   $logoutUrl
 * @var string   $mapUrl
 * @var string   $sessionsUrl
 * @var string   $statusUrl
 * @var string   $ingestUrl
 */
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iter — 내 동선</title>
    <style>
        html, body { margin: 0; font-family: system-ui, sans-serif; color: #222; }
        nav { display: flex; gap: 16px; padding: 12px 20px; border-bottom: 1px solid #ddd; align-items: center; }
        nav a { color: #222; text-decoration: none; font-size: 14px; }
        nav a:hover { text-decoration: underline; }
        main { padding: 40px 20px; max-width: 480px; margin: 0 auto; text-align: center; }
        .btn {
            display: inline-block; padding: 10px 20px; border-radius: 6px; border: none;
            background: #1a73e8; color: #fff; font-size: 15px; cursor: pointer; text-decoration: none;
        }
        .btn:disabled { background: #9db8e8; cursor: not-allowed; }
        #status { margin-top: 20px; font-size: 14px; color: #555; }
        #error { margin-top: 20px; font-size: 14px; color: #c0392b; }
    </style>
</head>
<body>
<?php if ($userId === null): ?>
    <main>
        <h1>Iter</h1>
        <p>구글 포토에서 사진을 선택하면 촬영 위치를 지도 위 동선으로 보여줍니다.</p>
        <a class="btn" href="<?= esc($loginUrl, 'attr') ?>">Google로 로그인</a>
    </main>
<?php else: ?>
    <nav>
        <a href="/">홈</a>
        <a href="<?= esc($mapUrl, 'attr') ?>">지도 보기</a>
        <a href="<?= esc($logoutUrl, 'attr') ?>">로그아웃</a>
    </nav>
    <main
        id="picker-flow"
        data-sessions-url="<?= esc($sessionsUrl, 'attr') ?>"
        data-status-url="<?= esc($statusUrl, 'attr') ?>"
        data-ingest-url="<?= esc($ingestUrl, 'attr') ?>"
        data-map-url="<?= esc($mapUrl, 'attr') ?>"
        data-login-url="<?= esc($loginUrl, 'attr') ?>"
    >
        <h1>사진 가져오기</h1>
        <button id="start-picker" class="btn">사진 선택하기</button>
        <div id="status"></div>
        <div id="error"></div>
    </main>

    <script>
        (function () {
            var root = document.getElementById('picker-flow');
            var startBtn = document.getElementById('start-picker');
            var statusEl = document.getElementById('status');
            var errorEl = document.getElementById('error');
            var pollTimer = null;
            var pollCount = 0;
            var MAX_POLLS = 150;
            var POLL_INTERVAL_MS = 2000;

            var urls = {
                sessions: root.dataset.sessionsUrl,
                status: root.dataset.statusUrl,
                ingest: root.dataset.ingestUrl,
                map: root.dataset.mapUrl,
                login: root.dataset.loginUrl
            };

            startBtn.addEventListener('click', startFlow);

            function startFlow() {
                reset();
                setBusy(true);
                statusEl.textContent = '세션을 만드는 중...';

                fetch(urls.sessions, { method: 'POST', headers: { Accept: 'application/json' } })
                    .then(handleJson)
                    .then(function (data) {
                        if (!data.pickerUri) { throw new Error('pickerUri 를 받지 못했습니다'); }
                        openPicker(data.pickerUri);
                        statusEl.textContent = '선택을 기다리는 중...';
                        pollCount = 0;
                        pollTimer = setTimeout(pollStatus, POLL_INTERVAL_MS);
                    })
                    .catch(onError);
            }

            function openPicker(pickerUri) {
                var win = window.open(pickerUri, '_blank');
                if (!win) {
                    var link = document.createElement('a');
                    link.id = 'picker-link';
                    link.href = pickerUri;
                    link.target = '_blank';
                    link.textContent = '여기를 눌러 구글 포토에서 사진을 선택하세요';
                    root.insertBefore(link, statusEl);
                }
            }

            function pollStatus() {
                pollCount++;
                if (pollCount > MAX_POLLS) {
                    onError(new Error('선택 시간이 초과됐습니다'));
                    return;
                }

                fetch(urls.status, { headers: { Accept: 'application/json' } })
                    .then(handleJson)
                    .then(function (data) {
                        if (data.mediaItemsSet) {
                            statusEl.textContent = '사진을 저장하는 중...';
                            ingest();
                        } else {
                            pollTimer = setTimeout(pollStatus, POLL_INTERVAL_MS);
                        }
                    })
                    .catch(onError);
            }

            function ingest() {
                fetch(urls.ingest, { method: 'POST', headers: { Accept: 'application/json' } })
                    .then(handleJson)
                    .then(function (data) {
                        statusEl.textContent = data.saved + '장 저장됨';
                        var link = document.createElement('a');
                        link.className = 'btn';
                        link.href = urls.map;
                        link.textContent = '지도에서 보기';
                        link.style.marginTop = '12px';
                        link.style.display = 'inline-block';
                        root.appendChild(link);
                        setBusy(false);
                    })
                    .catch(onError);
            }

            function handleJson(res) {
                if (!res.ok) {
                    return res.json().catch(function () { return {}; }).then(function (body) {
                        var err = new Error(body.error || ('요청 실패(' + res.status + ')'));
                        err.status = res.status;
                        throw err;
                    });
                }
                return res.json();
            }

            function onError(err) {
                clearTimeout(pollTimer);
                errorEl.textContent = (err && err.message) || '오류가 발생했습니다';
                errorEl.appendChild(document.createElement('br'));

                if (err && err.status === 401) {
                    // 세션 만료 등 인증 실패는 재시도 대신 로그인 페이지로 안내한다.
                    var loginLink = document.createElement('a');
                    loginLink.className = 'btn';
                    loginLink.href = urls.login;
                    loginLink.textContent = '다시 로그인하기';
                    errorEl.appendChild(loginLink);
                } else {
                    var retry = document.createElement('button');
                    retry.className = 'btn';
                    retry.type = 'button';
                    retry.textContent = '다시 시도';
                    retry.addEventListener('click', reset);
                    errorEl.appendChild(retry);
                }
                setBusy(false);
            }

            function setBusy(busy) {
                startBtn.disabled = busy;
            }

            function reset() {
                clearTimeout(pollTimer);
                pollCount = 0;
                statusEl.textContent = '';
                errorEl.innerHTML = '';
                var existingLink = document.getElementById('picker-link');
                if (existingLink) { existingLink.remove(); }
                setBusy(false);
            }
        })();
    </script>
<?php endif; ?>
</body>
</html>
```

- [ ] **Step 5: `welcome_message.php` 삭제**

```bash
cd Iter
git rm app/Views/welcome_message.php
```

- [ ] **Step 6: 테스트 통과 확인**

Run: `cd Iter && vendor/bin/phpunit tests/feature/HomeControllerTest.php`
Expected: `OK (2 tests, ...)`.

- [ ] **Step 7: 커밋**

```bash
cd Iter
git add app/Controllers/Home.php app/Views/home.php tests/feature/HomeControllerTest.php
git commit -m "✨ feat: 홈 화면 — 상단 메뉴 + Picker 흐름 UI(사진 선택→적재→지도 이동)"
```

---

### Task 3: 전체 검증 (composer ci + 런타임 스모크)

**Files:** 없음(검증 전용 태스크).

**Interfaces:** 없음.

- [ ] **Step 1: 전체 스위트 확인**

Run: `cd Iter && vendor/bin/phpunit`
Expected: `OK (...)` — 실패 0건.

- [ ] **Step 2: 전체 게이트**

Run: `cd Iter && composer cs-fix && composer ci`
Expected: `composer ci`가 exit code 0. (`cs-fix`는 `Views` 디렉터리를 검사 대상에서 제외하므로 `home.php`는 영향받지 않는다.)

- [ ] **Step 3: 라우트 등록 확인**

Run: `cd Iter && php spark routes | grep -E "auth/logout|^\| GET.*/ "`
Expected: `GET auth/logout → AuthController::logout` 행이 보인다.

- [ ] **Step 4: 실서버 스모크 — 비로그인 홈**

```bash
cd Iter
php spark serve --port 8250 > /tmp/iter-home-smoke.log 2>&1 &
sleep 3
curl -s http://localhost:8250/ | grep -o 'Google로 로그인'
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8250/auth/logout
kill %1 2>/dev/null
```

Expected: 첫 번째 curl이 `Google로 로그인` 출력, 두 번째가 `302`(로그아웃은 세션 유무와 무관하게 항상 `/`로 리다이렉트).

- [ ] **Step 5: 실제 브라우저로 전체 흐름 수동 확인 (사용자 검증)**

자동화 대상이 아니므로 개발자가 유효한 Google OAuth 설정이 된 인스턴스에서 직접 확인한다:
1. `/`에서 "Google로 로그인" 클릭 → 로그인 완료 후 `/`로 복귀, 상단 메뉴(`홈·지도 보기·로그아웃`)와 "사진 선택하기" 버튼이 보이는지.
2. "사진 선택하기" 클릭 → 새 탭에서 구글 포토 선택 화면이 열리는지, 원 탭에는 "선택을 기다리는 중..."이 표시되는지.
3. 사진을 선택하고 완료하면(구글 포토 쪽에서 완료 처리) 원 탭이 자동으로 "N장 저장됨" + "지도에서 보기" 버튼으로 전환되는지(최대 약 5분 내).
4. "지도에서 보기" 클릭 → `/map`에서 방금 저장한 좌표가 마커로 보이는지.
5. "로그아웃" 클릭 → `/`로 돌아가 랜딩(로그인 버튼만)으로 바뀌는지.

- [ ] **Step 6: 커밋 (있다면)**

Task 1·2에서 이미 각각 커밋했으므로, 이 태스크에서 코드 변경이 없다면 추가 커밋은 불필요하다. 검증 중 결함을 발견해 수정했다면 별도 커밋으로 남긴다(예: `🐛 fix: ...`).
