# 여행 상세 — 사진 확대 뷰어 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 여행 상세 페이지(`/trips/{id}`)의 인라인 시간표에서 사진 썸네일을 클릭하면, 지도 페이지(`map.php`)와 동일한 사진 확대 뷰어(확대·좌우 이동·90도 회전·삭제)가 열리도록 한다.

**Architecture:** 순수 프론트엔드 이식이다. `app/Views/map.php`의 `#photo-viewer` 컴포넌트(마크업·CSS·JS)를 `app/Views/trip-detail.php`에 그대로 복제하되, 대상 셀렉터를 `.timeline-photos img` 하나로 고정한다(map.php는 `#photo-layer-grid`도 있어 분기가 필요했지만 여기는 불필요). 신규 백엔드 엔드포인트는 없다 — 기존 `POST /photos/{id}/rotate`, `POST /photos/{id}/delete`(`PhotoController`, 소유권만 검증)를 그대로 재사용한다.

**Tech Stack:** CodeIgniter 4 뷰(순수 PHP + 인라인 vanilla JS, 프레임워크/빌드 없음), PHPUnit(컨트롤러가 `photosUrl`을 뷰에 전달하는지만 확인, 실제 뷰어 동작은 브라우저 실측).

## Global Constraints

- `declare(strict_types=1)` 모든 PHP 파일 필수.
- 새 백엔드 코드 없음 — `PhotoController::rotate()`/`delete()`, `app/Config/Routes.php`의 `photos/(:num)/rotate`·`photos/(:num)/delete` 라우트를 그대로 재사용한다.
- 좌우 이동 범위는 `.timeline-photos img` 전체(아코디언 구조상 한 번에 하나의 날짜 패널만 열려 있으므로 자연히 "현재 열린 날짜의 모든 시간대에 걸친 사진"이 된다).
- 회전은 캐시버스터(`?v={timestamp}`)로 뷰어·썸네일 `src`를 갱신한다(서버가 같은 파일 경로를 덮어쓰므로).
- 삭제 시: 뷰어 닫기 → 현재 열린 날짜 패널 접기 → 그 날짜의 `timelineCache` 항목 삭제 → `GET /trips/{id}/data` 재조회로 통계·날짜 목록·커버 후보를 갱신한다.
- `app/Views/*`는 PHPStan 분석 대상에서 제외된다(`phpstan.neon`의 `excludePaths`) — 뷰 파일의 JS/CSS 변경은 정적 분석에 영향 없음. `app/Controllers/TripController.php` 변경은 레벨 6 통과 필수.
- `composer ci`(CS Fixer → PHPStan → PHPUnit) 그린 없이 다음 태스크로 넘어가지 않는다. `composer check`는 CS Fixer를 빠뜨리므로 사용 금지.

---

## Task 1: `photosUrl` 전달 + 사진 확대 뷰어 이식

**Files:**
- Modify: `app/Controllers/TripController.php:173-189`(`show()` 메서드)
- Modify: `app/Views/trip-detail.php`(문서블록 1-14행, `<style>` 블록 103-112행 뒤에 추가, `<main>` 태그 117행, `</main>` 뒤 165행 부근에 마크업 추가, JS — `saveDayTimelineNote()` 함수 뒤 515행 부근에 뷰어 로직 추가)
- Test: `tests/feature/TripControllerTest.php:181-191`(`testShowRendersPageShell`)

**Interfaces:**
- Consumes: 기존 `POST /photos/{id}/rotate`(body: `direction=left|right`), `POST /photos/{id}/delete` — 둘 다 `App\Controllers\PhotoController`(변경 없음), 기존 `GET /trips/{id}/data` 응답 형태(`{trip, days, stats}` — `App\Controllers\TripController::showData()`, 변경 없음), 기존 전역 변수 `dayListEl`(`document.getElementById('day-list')`), `timelineCache`(날짜 → `/timeline/{date}` 응답), `openTimelineDate`(현재 열린 날짜 또는 `null`), `tripUrl`, `render(data)` 함수 — 모두 `trip-detail.php`에 이미 존재.
- Produces: 이 태스크로 끝나는 기능이라 이후 태스크가 소비할 새 인터페이스는 없다(Task 2는 검증 전용).

- [ ] **Step 1: 테스트 수정(RED)**

`tests/feature/TripControllerTest.php`의 `testShowRendersPageShell`(181-191행)을 다음으로 교체:

```php
    public function testShowRendersPageShell(): void
    {
        $result = $this->withSession(['user_id' => $this->userId])->get('trips/1');

        $result->assertStatus(200);
        $body = (string) $result->getBody();
        $this->assertStringContainsString('data-trip-id="1"', $body);
        // 인라인 시간표 펼치기에 필요한 timeline API URL과 토글 마크업이 포함돼야 한다.
        $this->assertStringContainsString('data-timeline-url', $body);
        $this->assertStringContainsString('day-timeline-toggle', $body);
        // 시간표 사진 확대 뷰어(회전·삭제)에 필요한 photos API URL과 뷰어 마크업이 포함돼야 한다.
        $this->assertStringContainsString('data-photos-url', $body);
        $this->assertStringContainsString('id="photo-viewer"', $body);
    }
```

- [ ] **Step 2: RED 확인**

Run: `vendor/bin/phpunit --no-coverage --filter testShowRendersPageShell tests/feature/TripControllerTest.php`
Expected: FAIL — `data-photos-url`/`id="photo-viewer"` 문자열이 응답 본문에 없음.

- [ ] **Step 3: 컨트롤러에 `photosUrl` 추가**

`app/Controllers/TripController.php`의 `show()` 메서드(173-189행) 전체를 다음으로 교체:

```php
    public function show(int $id): ResponseInterface|RedirectResponse|string
    {
        if ($this->currentUserId() === null) {
            return redirect()->to('/auth/google');
        }

        helper('url');

        return view('trip-detail', [
            'tripId' => $id,
            'tripsUrl' => site_url('trips'),
            'timelineUrl' => site_url('timeline'),
            'photosUrl' => site_url('photos'),
            'uploadUrl' => site_url('upload'),
            'mapUrl' => site_url('map'),
            'logoutUrl' => site_url('auth/logout'),
        ]);
    }
```

- [ ] **Step 4: 뷰 문서블록·`<main>` 속성 수정**

`app/Views/trip-detail.php`의 문서블록(1-14행)을 다음으로 교체:

```php
<?php

declare(strict_types=1);

/**
 * 여행 상세/편집 — 제목·설명·기간·커버 수정, 포함된 날짜 목록(인라인 시간표 펼치기 포함),
 * 시간표 사진 확대 뷰어(좌우 이동·회전·삭제).
 *
 * @var int    $tripId
 * @var string $tripsUrl
 * @var string $timelineUrl 시간별 동선 API URL 프리픽스(GET /timeline/{date} 등) — map.php 와 공유
 * @var string $photosUrl   사진 관리 API URL 프리픽스(POST /photos/{id}/rotate 등) — map.php 와 공유
 * @var string $uploadUrl
 * @var string $mapUrl
 * @var string $logoutUrl
 */
```

같은 파일의 `<main>` 태그(117행)를 다음으로 교체:

```php
    <main data-trips-url="<?= esc($tripsUrl, 'attr') ?>" data-trip-id="<?= (int) $tripId ?>" data-timeline-url="<?= esc($timelineUrl, 'attr') ?>" data-photos-url="<?= esc($photosUrl, 'attr') ?>">
```

- [ ] **Step 5: 뷰어 CSS 추가**

`app/Views/trip-detail.php`의 `<style>` 블록에서 `.share-option:hover { background: #f4f6fb; }`(112행) 바로 뒤, `</style>`(113행) 앞에 다음 CSS를 추가:

```css

        /* ── 사진 확대 뷰어(시간표 썸네일 클릭 시 크게 표시) ── */
        .timeline-photos img { cursor: zoom-in; }
        #photo-viewer {
            position: fixed; inset: 0; z-index: 3000; background: rgba(0, 0, 0, 0.88);
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            padding: 24px; cursor: zoom-out;
        }
        #photo-viewer[hidden] { display: none; }
        #photo-viewer img {
            max-width: 92vw; max-height: 82vh; border-radius: 8px;
            box-shadow: 0 8px 40px rgba(0, 0, 0, 0.5);
        }
        #photo-viewer-caption { margin-top: 12px; color: #ddd; font-size: 13px; }
        #photo-viewer-controls { margin-top: 14px; display: flex; gap: 10px; cursor: default; }
        .viewer-ctl-btn {
            border: 1px solid rgba(255, 255, 255, 0.4); border-radius: 8px; background: rgba(255, 255, 255, 0.12);
            color: #fff; font-size: 13px; padding: 8px 14px; cursor: pointer;
        }
        .viewer-ctl-btn:hover { background: rgba(255, 255, 255, 0.25); }
        .viewer-ctl-btn:disabled { opacity: 0.5; cursor: default; }
        .viewer-ctl-danger { border-color: rgba(255, 120, 120, 0.6); color: #ffb4b4; }
        #photo-viewer-close {
            position: absolute; top: 14px; right: 20px; border: none; background: none;
            color: #fff; font-size: 28px; cursor: pointer; line-height: 1;
        }
        .viewer-nav-btn {
            position: absolute; top: 50%; transform: translateY(-50%);
            border: none; border-radius: 50%; width: 46px; height: 46px;
            background: rgba(255, 255, 255, 0.15); color: #fff; font-size: 20px;
            cursor: pointer; line-height: 1; z-index: 1;
        }
        .viewer-nav-btn:hover { background: rgba(255, 255, 255, 0.3); }
        .viewer-nav-btn[hidden] { display: none; }
        #photo-viewer-prev { left: 18px; }
        #photo-viewer-next { right: 18px; }
```

- [ ] **Step 6: 뷰어 마크업 추가**

`app/Views/trip-detail.php`의 `</main>`(164행) 바로 뒤, `<script>`(166행) 앞에 다음 마크업을 추가:

```html

    <div id="photo-viewer" hidden>
        <button type="button" id="photo-viewer-close" aria-label="닫기">&times;</button>
        <button type="button" id="photo-viewer-prev" class="viewer-nav-btn" aria-label="이전 사진">&#10094;</button>
        <button type="button" id="photo-viewer-next" class="viewer-nav-btn" aria-label="다음 사진">&#10095;</button>
        <img id="photo-viewer-img" src="" alt="">
        <div id="photo-viewer-caption"></div>
        <div id="photo-viewer-controls">
            <button type="button" class="viewer-ctl-btn" id="photo-viewer-rotate-left" title="왼쪽으로 회전">⟲ 왼쪽</button>
            <button type="button" class="viewer-ctl-btn" id="photo-viewer-rotate-right" title="오른쪽으로 회전">⟳ 오른쪽</button>
            <button type="button" class="viewer-ctl-btn viewer-ctl-danger" id="photo-viewer-delete" title="사진 삭제">🗑 삭제</button>
        </div>
    </div>
```

- [ ] **Step 7: 뷰어 JS 로직 추가**

`app/Views/trip-detail.php`의 `saveDayTimelineNote()` 함수가 끝나는 줄(515행, 함수를 닫는 `}`) 바로 뒤, `document.getElementById('save-btn').addEventListener(...)`(517행) 앞에 다음 JS를 추가:

```javascript

            // ── 사진 확대 뷰어(시간표 썸네일 클릭 시 크게 표시, 좌우 이동·회전·삭제) ──

            var photosUrl = mainEl.dataset.photosUrl;
            var viewerEl = document.getElementById('photo-viewer');
            var viewerImgEl = document.getElementById('photo-viewer-img');
            var viewerCaptionEl = document.getElementById('photo-viewer-caption');
            var viewerPrevEl = document.getElementById('photo-viewer-prev');
            var viewerNextEl = document.getElementById('photo-viewer-next');
            var viewerPhotoId = null;
            var viewerList = [];   // 현재 열린 날짜 패널에서 넘겨볼 수 있는 썸네일 목록
            var viewerIndex = -1;

            // 클릭한 썸네일이 속한(현재 열린 날짜 패널의) 사진 목록을 수집해 그 위치부터 넘겨본다.
            function openViewerFrom(imgEl) {
                viewerList = Array.prototype.slice.call(document.querySelectorAll('.timeline-photos img'));
                showViewerAt(viewerList.indexOf(imgEl));
                viewerEl.hidden = false;
            }

            function showViewerAt(index) {
                if (index < 0 || index >= viewerList.length) { return; }
                viewerIndex = index;

                var imgEl = viewerList[index];
                viewerImgEl.src = imgEl.src;
                viewerCaptionEl.textContent = imgEl.title || '';
                // 회전·삭제 대상 식별 — 썸네일 URL(/thumbnails/{id})에서 id 를 뽑는다.
                var match = imgEl.src.match(/\/thumbnails\/(\d+)/);
                viewerPhotoId = match ? match[1] : null;

                viewerPrevEl.hidden = index <= 0;
                viewerNextEl.hidden = index >= viewerList.length - 1;
            }

            function closeViewer() {
                viewerEl.hidden = true;
                viewerImgEl.src = '';
                viewerPhotoId = null;
                viewerList = [];
                viewerIndex = -1;
            }

            viewerPrevEl.addEventListener('click', function () { showViewerAt(viewerIndex - 1); });
            viewerNextEl.addEventListener('click', function () { showViewerAt(viewerIndex + 1); });

            // 컨트롤·이동 버튼 클릭은 닫힘으로 처리하지 않는다(닫기 버튼은 컨트롤 밖이라
            // 클릭이 그대로 버블링돼 아래 배경 클릭 처리로 닫힌다).
            viewerEl.addEventListener('click', function (evt) {
                if (evt.target.closest('#photo-viewer-controls') || evt.target.closest('.viewer-nav-btn')) { return; }
                closeViewer();
            });
            document.addEventListener('keydown', function (evt) {
                if (viewerEl.hidden) { return; }
                if (evt.key === 'Escape') { closeViewer(); }
                if (evt.key === 'ArrowLeft') { showViewerAt(viewerIndex - 1); }
                if (evt.key === 'ArrowRight') { showViewerAt(viewerIndex + 1); }
            });

            // 사진 썸네일은 날짜 패널이 열릴 때마다 새로 그려지므로 이벤트 위임으로 클릭을 잡는다.
            document.body.addEventListener('click', function (evt) {
                var photoImg = evt.target.closest('.timeline-photos img');
                if (photoImg) { openViewerFrom(photoImg); }
            });

            // 회전 — 서버가 보관 썸네일을 90도 회전해 저장하면, 캐시를 우회해 다시 그린다.
            function rotateViewerPhoto(direction, buttonEl) {
                if (!viewerPhotoId) { return; }
                buttonEl.disabled = true;
                fetch(photosUrl + '/' + viewerPhotoId + '/rotate', {
                    method: 'POST',
                    headers: { Accept: 'application/json' },
                    body: new URLSearchParams({ direction: direction })
                })
                    .then(function (res) {
                        if (!res.ok) { throw new Error('rotate failed'); }
                        var bust = '?v=' + Date.now();
                        var freshSrc = '/thumbnails/' + viewerPhotoId + bust;
                        viewerImgEl.src = freshSrc;
                        // 열려 있는 날짜 패널의 같은 썸네일도 갱신한다.
                        document.querySelectorAll('.timeline-photos img').forEach(function (img) {
                            if (img.src.indexOf('/thumbnails/' + viewerPhotoId) !== -1) { img.src = freshSrc; }
                        });
                    })
                    .catch(function () { alert('회전에 실패했습니다.'); })
                    .then(function () { buttonEl.disabled = false; });
            }

            document.getElementById('photo-viewer-rotate-left').addEventListener('click', function () {
                rotateViewerPhoto('left', this);
            });
            document.getElementById('photo-viewer-rotate-right').addEventListener('click', function () {
                rotateViewerPhoto('right', this);
            });

            // 삭제 — 썸네일 파일과 위치 기록(DB)이 함께 삭제된다. 뷰어·열린 날짜 패널을 닫고,
            // 그 날짜의 캐시를 지운 뒤 여행 데이터를 다시 불러와 통계·날짜 목록·표지 후보를 갱신한다.
            document.getElementById('photo-viewer-delete').addEventListener('click', function () {
                if (!viewerPhotoId) { return; }
                if (!window.confirm('이 사진을 삭제할까요? 썸네일과 위치 기록이 함께 삭제됩니다.')) { return; }

                var buttonEl = this;
                var deletedDate = openTimelineDate;
                buttonEl.disabled = true;
                fetch(photosUrl + '/' + viewerPhotoId + '/delete', {
                    method: 'POST',
                    headers: { Accept: 'application/json' }
                })
                    .then(function (res) {
                        if (!res.ok) { throw new Error('delete failed'); }
                        closeViewer();

                        if (deletedDate !== null) {
                            var toggleEl = dayListEl.querySelector('.day-timeline-toggle[data-date="' + deletedDate + '"]');
                            var panelEl = dayListEl.querySelector('.day-timeline-panel[data-date="' + deletedDate + '"]');
                            if (panelEl) { panelEl.hidden = true; }
                            if (toggleEl) { toggleEl.textContent = '시간표 보기'; }
                            openTimelineDate = null;
                            delete timelineCache[deletedDate];
                        }

                        return fetch(tripUrl + '/data', { headers: { Accept: 'application/json' } });
                    })
                    .then(function (res) { return res.json(); })
                    .then(render)
                    .catch(function () { alert('삭제에 실패했습니다.'); })
                    .then(function () { buttonEl.disabled = false; });
            });
```

- [ ] **Step 8: GREEN 확인**

Run: `vendor/bin/phpunit --no-coverage --filter testShowRendersPageShell tests/feature/TripControllerTest.php`
Expected: `OK (1 test, 5 assertions)`

- [ ] **Step 9: 전체 테스트·정적분석 확인**

Run: `composer ci`
Expected: `[OK] No errors`(PHPStan — `app/Views/*`는 분석 제외 대상이라 뷰 파일의 JS/CSS 변경은 영향 없음, `TripController.php` 변경만 검사됨), CS Fixer diff 없음, PHPUnit 전체 `OK (...)`.

- [ ] **Step 10: 커밋**

```bash
git add app/Controllers/TripController.php app/Views/trip-detail.php tests/feature/TripControllerTest.php
git commit -m "✨ feat: 여행 상세 시간표에 사진 확대 뷰어 추가"
```

---

## Task 2: 브라우저 실측 검증

**Files:** 없음(검증 전용 태스크)

**Interfaces:** 없음.

- [ ] **Step 1: 로컬 SQLite 임시 DB로 런타임 구동 확인**

`.env`를 임시로 SQLite3 로 바꿔 `php spark migrate` 후 `mcp__Claude_Browser__preview_start`(`.claude/launch.json`의 `iter-serve`)로 실제 화면을 확인한다. `app.baseURL`은 **기존 활성 라인을 직접 수정**해야 한다(파일 끝에 새 줄을 추가하면 앞쪽의 기존 활성 라인이 먼저 읽혀 무시된다).

```bash
cp .env .env.bak-photo-viewer-verify
python3 - << 'EOF'
p = '.env'
s = open(p).read().replace(
    "app.baseURL = 'http://localhost:8080/'",
    "app.baseURL = 'http://localhost:8299/'",
)
s += "\n# TEMP photo-viewer-verify\ndatabase.default.database = dev-photo-viewer.db\ndatabase.default.DBDriver = SQLite3\ndatabase.default.DBPrefix =\n"
open(p, 'w').write(s)
EOF
php spark migrate
```

Expected: 기존 마이그레이션들이 재실행되고 `Migrations complete.`(이번 태스크는 신규 마이그레이션이 없다).

- [ ] **Step 2: 시드 데이터 준비(같은 날짜에 서로 다른 두 장의 사진)**

좌우 이동·회전·삭제를 모두 확인하려면 한 날짜에 사진이 2장 이상 있어야 한다.

```bash
mkdir -p writable/uploads/thumbnails
php -r '
$colors = [[220,60,60],[60,140,220]];
foreach ($colors as $i => $c) {
    $im = imagecreatetruecolor(300, 200);
    imagefill($im, 0, 0, imagecolorallocate($im, $c[0], $c[1], $c[2]));
    imagejpeg($im, "writable/uploads/thumbnails/verify-{$i}.jpg");
}'
sqlite3 writable/dev-photo-viewer.db << EOF
INSERT INTO users (google_sub, email, name, created_at, updated_at) VALUES ('dev-sub', 'dev@example.com', 'Dev', datetime('now'), datetime('now'));
INSERT INTO photo_locations (user_id, source_item_id, lat, lng, thumbnail_path, taken_at, created_at) VALUES
 (1, 'p1', 37.5796, 126.9770, '$(pwd)/writable/uploads/thumbnails/verify-0.jpg', '2024-03-15 00:10:00', datetime('now')),
 (1, 'p2', 37.5796, 126.9770, '$(pwd)/writable/uploads/thumbnails/verify-1.jpg', '2024-03-15 00:20:00', datetime('now'));
INSERT INTO trips (user_id, title, body, start_date, end_date, cover_photo_id, created_at, updated_at) VALUES
 (1, '서울 여행', '고궁 투어', '2024-03-15', '2024-03-15', NULL, datetime('now'), datetime('now'));
EOF
```

Expected: 오류 없이 완료(경복궁 부근 좌표 2장이 같은 시간대(같은 30m 반경, 10분 간격)로 들어가 한 시간표 슬롯의 `.timeline-photos`에 나란히 표시됨, 여행 1건 저장).

- [ ] **Step 3: 브라우저로 확인**

`mcp__Claude_Browser__preview_start`(`name: "iter-serve"`)로 서버를 띄우고, 새로 생긴 세션 파일에 `user_id|i:1;`을 주입한 뒤 `/trips/1`에 접속해 다음을 확인한다:

1. "포함된 날짜" 목록에서 `2024-03-15`의 "시간표 보기"를 클릭해 패널을 연다. 사진 2장이 `.timeline-photos` 안에 나란히 보인다.
2. 첫 번째 사진 썸네일을 클릭 → 화면 전체를 덮는 뷰어가 열리고 원본 크기로 표시된다. 이전 버튼(◀)은 숨겨져 있고 다음 버튼(▶)은 보인다.
3. 다음 버튼(▶) 클릭 → 두 번째 사진으로 전환되며 이번엔 다음 버튼이 숨고 이전 버튼이 보인다. 키보드 `ArrowLeft`를 눌러 다시 첫 번째 사진으로 돌아가는지 확인한다.
4. "오른쪽으로 회전" 버튼 클릭 → `mcp__Claude_Browser__read_network_requests`로 `POST /photos/{id}/rotate`가 200으로 나갔는지 확인하고, 뷰어 이미지의 `src`에 `?v=`캐시버스터가 붙어 갱신됐는지 확인한다(`mcp__Claude_Browser__javascript_tool`로 `document.getElementById('photo-viewer-img').src` 조회).
5. `Escape` 키를 눌러 뷰어를 닫는다 → 뷰어가 사라지고 날짜 패널은 그대로 열려 있다.
6. 다시 사진을 클릭해 뷰어를 열고 "삭제" 버튼 클릭 → 확인 대화상자에서 확인 → `POST /photos/{id}/delete`가 200으로 나가는지 확인한다. 뷰어가 닫히고, 날짜 패널도 접히며(`day-timeline-toggle`의 텍스트가 "시간표 보기"로 돌아옴), `GET /trips/1/data`가 다시 요청되고 상단 통계·날짜 목록이 갱신되는지 확인한다.

Expected: 위 6개 동작이 화면·네트워크 요청으로 모두 확인됨.

- [ ] **Step 4: 환경 원복**

```bash
mv .env.bak-photo-viewer-verify .env
rm -f writable/dev-photo-viewer.db writable/uploads/thumbnails/verify-*.jpg
rm -f writable/session/ci_session*
```

Expected: `.env`가 원래 상태로 복원되고, 임시 DB·썸네일·세션 파일이 모두 제거됨.

- [ ] **Step 5: 최종 확인**

Run: `git status --short`
Expected: 추적되지 않은 `.claude/`(세션 전용, 무시)를 제외하고 워킹트리 깨끗 — 모든 변경은 Task 1에서 이미 커밋됨.

이 태스크는 커밋할 코드 변경이 없다(검증 전용).

---

## Self-Review

**스펙 커버리지 확인:**
- `TripController::show()`에 `photosUrl` 전달 → Task 1 Step 3. ✅
- `#photo-viewer` 마크업·CSS 이식 → Task 1 Step 5-6. ✅
- 대상 셀렉터를 `.timeline-photos img`로 고정(분기 불필요) → Task 1 Step 7 `openViewerFrom()`. ✅
- 사진 클릭 이벤트 위임(`document.body`) → Task 1 Step 7. ✅
- 좌우 이동(아코디언 구조상 열린 날짜 전체) → Task 1 Step 7 `openViewerFrom()`이 `.timeline-photos img` 전체를 수집. ✅
- 회전 시 캐시버스터 갱신 → Task 1 Step 7 `rotateViewerPhoto()`. ✅
- 삭제 시 뷰어 닫기 + 날짜 패널 접기 + `timelineCache` 무효화 + `/data` 재조회 → Task 1 Step 7 삭제 리스너. ✅
- 엣지 케이스(첫/마지막 사진에서 이전/다음 버튼 숨김, 배경 클릭 닫기) → Task 1 Step 7 `showViewerAt()`·`viewerEl` 클릭 핸들러(map.php와 동일 로직). ✅
- 백엔드 신규 코드 없음(기존 rotate/delete API 재사용) → Task 1에 컨트롤러·라우트 변경 없음(TripController::show()의 view 배열 추가만). ✅
- 테스트 전략(마크업 존재 확인 + 브라우저 실측) → Task 1 Step 1(테스트), Task 2(브라우저). ✅

**플레이스홀더 스캔:** "TODO"/"TBD"/"적절히 처리" 없음. 모든 스텝에 완전한 코드 첨부.

**타입 일관성 확인:** `openViewerFrom(imgEl)`·`showViewerAt(index)`·`closeViewer()`·`rotateViewerPhoto(direction, buttonEl)` 시그니처가 `map.php`의 원본과 동일(단, `openViewerFrom`은 분기 없이 `.timeline-photos img` 고정). 삭제 핸들러가 참조하는 `dayListEl`·`timelineCache`·`openTimelineDate`·`tripUrl`·`render`는 모두 `trip-detail.php`에 이미 선언된 최상위 변수/함수이며, 이 태스크의 삽입 지점(517행 이전)이 그 선언들(178-234행)보다 뒤에 있어 참조 시점에 문제가 없다(클릭 핸들러는 사용자 상호작용 이후에만 실행되므로, 스크립트 전체가 먼저 평가된 뒤 호출된다).
