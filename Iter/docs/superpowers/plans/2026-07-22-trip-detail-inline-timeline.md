# 여행 상세 — 인라인 시간표 펼치기 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 여행 상세 페이지(`/trips/{id}`)의 "포함된 날짜" 목록에서 "이 날 시간표 보기"(지도 페이지로 이동하는 링크)를, 페이지를 벗어나지 않고 그 날의 전체 시간표(사진·주변 업장·메모·날짜 노트)를 바로 볼 수 있는 인라인 아코디언 토글로 바꾼다.

**Architecture:** 순수 프론트엔드 변경이다. `app/Views/map.php`의 시간표 레이어 JS(사진 먼저 렌더 → POI 순차 조회 → 메모/노트 저장) 패턴을 `app/Views/trip-detail.php`의 날짜 목록에 인라인 형태로 이식한다. 신규 백엔드 엔드포인트는 없다 — 기존 `GET /timeline/{date}`, `GET /timeline/poi`, `POST /timeline/day-note`, `POST /timeline/time-note`를 그대로 재사용한다.

**Tech Stack:** CodeIgniter 4 뷰(순수 PHP + 인라인 vanilla JS, 프레임워크/빌드 없음), PHPUnit(피처 테스트로 마크업 존재만 확인, 실제 동작은 브라우저 실측).

## Global Constraints

- `declare(strict_types=1)` 모든 PHP 파일 필수.
- 사진 클릭 시 확대(뷰어·회전·삭제)는 이번 범위 제외 — 썸네일만 표시.
- 여러 날짜 동시 펼침 금지 — 아코디언(한 번에 하나만 열림).
- POI 조회는 사진 로드 완료 후 슬롯마다 **순차** 호출(병렬 금지) — 기존 `map.php` 원칙 유지.
- 레이트리밋(`poi` 300/시간, `notes` 120/시간)은 지도 페이지와 공유 — 신규 버킷 만들지 않음.
- `composer ci`(CS Fixer → PHPStan → PHPUnit) 그린 없이 다음 태스크로 넘어가지 않는다. `composer check`는 CS Fixer를 빠뜨리므로 사용 금지.

---

## Task 1: 인라인 시간표 패널 — 토글·표시·저장

**Files:**
- Modify: `app/Controllers/TripController.php:152-167`(`show()` 메서드)
- Modify: `app/Views/trip-detail.php`(문서블록, `<main>` 속성, `<style>`, `renderDayList()` 교체, 신규 JS 함수 추가)
- Modify: `tests/feature/TripControllerTest.php:181-188`(`testShowRendersPageShell`)

**Interfaces:**
- Consumes: 기존 `GET /timeline/{date}` 응답(`{date, day_note: {title, body}|null, slots: [{slot, label, lat, lng, photos: [{media_item_id, taken_at, thumbnail_url}], memo}]}`), `GET /timeline/poi?lat=&lng=` 응답(`{places: [{name, category}]}`), `POST /timeline/day-note`(date, title, body), `POST /timeline/time-note`(date, slot, memo) — 모두 기존 `TimelineController`(변경 없음).
- Produces: 이 태스크로 끝나는 기능이라 이후 태스크가 소비할 새 인터페이스는 없다(Task 2는 검증 전용).

- [ ] **Step 1: 테스트 수정(RED)**

`tests/feature/TripControllerTest.php`의 `testShowRendersPageShell`(181-188행)을 다음으로 교체:

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
    }
```

- [ ] **Step 2: RED 확인**

Run: `vendor/bin/phpunit --no-coverage --filter testShowRendersPageShell tests/feature/TripControllerTest.php`
Expected: FAIL — `data-timeline-url`/`day-timeline-toggle` 문자열이 응답 본문에 없음.

- [ ] **Step 3: 컨트롤러에 `timelineUrl` 추가**

`app/Controllers/TripController.php`의 `show()` 메서드(152-167행) 전체를 다음으로 교체:

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
            'uploadUrl' => site_url('upload'),
            'mapUrl' => site_url('map'),
            'logoutUrl' => site_url('auth/logout'),
        ]);
    }
```

- [ ] **Step 4: 뷰 문서블록·`<main>` 속성 수정**

`app/Views/trip-detail.php`의 문서블록(5-13행)을 다음으로 교체:

```php
/**
 * 여행 상세/편집 — 제목·설명·기간·커버 수정, 포함된 날짜 목록(인라인 시간표 펼치기 포함).
 *
 * @var int    $tripId
 * @var string $tripsUrl
 * @var string $timelineUrl 시간별 동선 API URL 프리픽스(GET /timeline/{date} 등) — map.php 와 공유
 * @var string $uploadUrl
 * @var string $mapUrl
 * @var string $logoutUrl
 */
```

같은 파일의 `<main>` 태그(71행)를 다음으로 교체:

```php
    <main data-trips-url="<?= esc($tripsUrl, 'attr') ?>" data-trip-id="<?= (int) $tripId ?>" data-timeline-url="<?= esc($timelineUrl, 'attr') ?>">
```

- [ ] **Step 5: CSS 추가·수정**

`app/Views/trip-detail.php`의 `<style>` 블록에서 다음 두 규칙:

```css
        .day-list li {
            display: flex; align-items: center; justify-content: space-between;
            padding: 10px 0; border-bottom: 1px solid #f0f0f0; font-size: 13px;
        }
        .day-list a { color: #1a73e8; text-decoration: none; font-size: 12px; }
```

를 다음으로 교체(`.day-list li` → `.day-list .day-row`로 범위를 좁혀 이후 추가할 `.day-timeline-panel`이 같은 `<ul>` 안에서 다른 레이아웃을 쓸 수 있게 하고, 더 이상 안 쓰는 `<a>` 규칙은 제거):

```css
        .day-list .day-row {
            display: flex; align-items: center; justify-content: space-between;
            padding: 10px 0; border-bottom: 1px solid #f0f0f0; font-size: 13px;
        }
        .day-timeline-toggle {
            flex: none; border: 1px solid #c7d2e0; border-radius: 6px; background: #fff;
            color: #1a73e8; font-size: 12px; padding: 4px 10px; cursor: pointer;
        }
        .day-timeline-toggle:hover { background: #f0f4ff; }
        .day-timeline-panel { display: block; padding: 14px 0 18px; border-bottom: 1px solid #f0f0f0; }
        .day-timeline-panel[hidden] { display: none; }
        .day-note-card {
            background: #f7f9ff; border: 1px solid #dbe5ff; border-radius: 10px;
            padding: 12px 14px 10px; margin-bottom: 16px;
        }
        .day-note-card input, .day-note-card textarea {
            width: 100%; box-sizing: border-box; border: 1px solid #ccd6f0; border-radius: 6px;
            font: inherit; padding: 6px 9px; background: #fff; margin-bottom: 8px;
        }
        .day-note-card textarea { resize: vertical; min-height: 44px; font-size: 13px; }
        .timeline-slot { display: flex; }
        .timeline-slot-time {
            flex: none; width: 52px; text-align: right; padding-top: 1px;
            font-weight: 600; font-size: 13px; color: #1a73e8;
        }
        .timeline-slot-content {
            flex: 1; min-width: 0; margin-left: 14px; padding: 0 0 16px 16px;
            border-left: 2px solid #dbe5ff; position: relative;
        }
        .timeline-slot:last-child .timeline-slot-content { border-left-color: transparent; }
        .timeline-slot-content::before {
            content: ''; position: absolute; left: -6px; top: 3px;
            width: 10px; height: 10px; border-radius: 50%; background: #1a73e8;
        }
        .timeline-count { font-size: 12px; color: #555; }
        .timeline-poi { font-size: 12px; color: #777; margin: 4px 0 2px; }
        .timeline-photos { display: flex; flex-wrap: wrap; gap: 6px; margin: 8px 0 2px; }
        .timeline-photos img { width: 84px; height: 84px; object-fit: cover; border-radius: 8px; display: block; }
        .timeline-memo { display: flex; gap: 6px; margin-top: 8px; }
        .timeline-memo input {
            flex: 1; min-width: 0; border: 1px solid #ddd; border-radius: 6px;
            padding: 5px 9px; font: inherit; font-size: 13px;
        }
        .note-save-btn {
            border: none; border-radius: 6px; background: #1a73e8; color: #fff;
            font-size: 12px; padding: 5px 12px; cursor: pointer;
        }
        .note-save-btn:disabled { opacity: 0.6; cursor: default; }
        .timeline-empty { color: #777; font-size: 13px; padding: 8px 0; }
```

- [ ] **Step 6: `renderDayList()` 교체 + 신규 함수 추가**

`app/Views/trip-detail.php`의 `renderDayList()` 함수(178-194행) 전체를 다음으로 교체:

```javascript
            var timelineUrl = mainEl.dataset.timelineUrl;
            var timelineCache = {}; // date → 최근 /timeline/{date} 응답(재조회 방지, 저장 성공 시 갱신)
            var openTimelineDate = null; // 현재 펼쳐진 날짜(아코디언 — 한 번에 하나만 열림)

            function renderDayList(days) {
                dayListEl.innerHTML = '';
                days.forEach(function (day) {
                    var rowEl = document.createElement('li');
                    rowEl.className = 'day-row';

                    var label = document.createElement('span');
                    label.textContent = day.date + ' · 사진 ' + day.photo_count + '장';
                    rowEl.appendChild(label);

                    var toggleEl = document.createElement('button');
                    toggleEl.type = 'button';
                    toggleEl.className = 'day-timeline-toggle';
                    toggleEl.dataset.date = day.date;
                    toggleEl.textContent = '시간표 보기';
                    rowEl.appendChild(toggleEl);

                    var panelEl = document.createElement('li');
                    panelEl.className = 'day-timeline-panel';
                    panelEl.dataset.date = day.date;
                    panelEl.hidden = true;

                    toggleEl.addEventListener('click', function () {
                        toggleDayTimeline(day.date, toggleEl, panelEl);
                    });

                    dayListEl.appendChild(rowEl);
                    dayListEl.appendChild(panelEl);
                });
            }

            function toggleDayTimeline(date, toggleEl, panelEl) {
                // 이미 열려있는 걸 다시 누르면 접는다.
                if (openTimelineDate === date) {
                    panelEl.hidden = true;
                    toggleEl.textContent = '시간표 보기';
                    openTimelineDate = null;
                    return;
                }

                // 아코디언 — 다른 날짜가 열려 있으면 먼저 접는다.
                if (openTimelineDate !== null) {
                    var prevToggle = dayListEl.querySelector('.day-timeline-toggle[data-date="' + openTimelineDate + '"]');
                    var prevPanel = dayListEl.querySelector('.day-timeline-panel[data-date="' + openTimelineDate + '"]');
                    if (prevPanel) { prevPanel.hidden = true; }
                    if (prevToggle) { prevToggle.textContent = '시간표 보기'; }
                }

                openTimelineDate = date;
                toggleEl.textContent = '접기';
                panelEl.hidden = false;

                if (timelineCache[date]) {
                    renderDayTimelinePanel(panelEl, date, timelineCache[date]);
                    return;
                }

                panelEl.innerHTML = '';
                panelEl.appendChild(timelineEmptyMessage('불러오는 중…'));

                fetch(timelineUrl + '/' + date, { headers: { Accept: 'application/json' } })
                    .then(function (res) {
                        if (!res.ok) { throw new Error('timeline fetch failed'); }
                        return res.json();
                    })
                    .then(function (data) {
                        timelineCache[date] = data;
                        renderDayTimelinePanel(panelEl, date, data);
                    })
                    .catch(function () {
                        panelEl.innerHTML = '';
                        panelEl.appendChild(timelineEmptyMessage('시간별 동선을 불러오지 못했습니다.'));
                    });
            }

            function timelineEmptyMessage(text) {
                var el = document.createElement('div');
                el.className = 'timeline-empty';
                el.textContent = text;
                return el;
            }

            function renderDayTimelinePanel(panelEl, date, data) {
                panelEl.innerHTML = '';

                var noteEl = document.createElement('div');
                noteEl.className = 'day-note-card';

                var noteTitleEl = document.createElement('input');
                noteTitleEl.type = 'text';
                noteTitleEl.maxLength = 100;
                noteTitleEl.placeholder = '이 날의 제목';
                noteTitleEl.value = data.day_note ? data.day_note.title : '';
                noteEl.appendChild(noteTitleEl);

                var noteBodyEl = document.createElement('textarea');
                noteBodyEl.maxLength = 2000;
                noteBodyEl.placeholder = '이 날의 메모';
                noteBodyEl.value = data.day_note ? data.day_note.body : '';
                noteEl.appendChild(noteBodyEl);

                var noteSaveEl = document.createElement('button');
                noteSaveEl.type = 'button';
                noteSaveEl.className = 'note-save-btn';
                noteSaveEl.textContent = '저장';
                noteSaveEl.addEventListener('click', function () {
                    saveDayTimelineNote('day-note', {
                        date: date,
                        title: noteTitleEl.value.trim(),
                        body: noteBodyEl.value.trim()
                    }, noteSaveEl, function () {
                        timelineCache[date].day_note = { title: noteTitleEl.value.trim(), body: noteBodyEl.value.trim() };
                    });
                });
                noteEl.appendChild(noteSaveEl);

                panelEl.appendChild(noteEl);

                if (!data.slots.length) {
                    panelEl.appendChild(timelineEmptyMessage('이 날짜에 표시할 사진이 없습니다.'));
                    return;
                }

                var poiTasks = []; // {el, lat, lng} — 사진이 먼저 뜨도록 POI 조회는 뒤로 미룬다.
                var pendingImgs = [];
                data.slots.forEach(function (slot) {
                    panelEl.appendChild(buildDayTimelineSlot(date, slot, poiTasks, pendingImgs));
                });

                // 썸네일이 모두 뜬 뒤(최대 4초 대기) 주변 업장 정보를 한 건씩 차례로 불러온다.
                // POI 를 먼저·병렬로 부르면 세션 잠금 탓에 썸네일 응답이 그 뒤로 밀린다.
                whenImagesSettled(pendingImgs, 4000).then(function () {
                    poiTasks.reduce(function (chain, task) {
                        return chain.then(function () {
                            // 패널을 닫았거나 다른 날짜로 넘어갔으면 중단.
                            if (panelEl.hidden || openTimelineDate !== date) { return; }
                            return loadPoi(task.el, task.lat, task.lng);
                        });
                    }, Promise.resolve());
                });
            }

            function buildDayTimelineSlot(date, slot, poiTasks, pendingImgs) {
                var rowEl = document.createElement('div');
                rowEl.className = 'timeline-slot';

                var timeEl = document.createElement('div');
                timeEl.className = 'timeline-slot-time';
                timeEl.textContent = slot.label;
                rowEl.appendChild(timeEl);

                var contentEl = document.createElement('div');
                contentEl.className = 'timeline-slot-content';

                if (slot.photos.length) {
                    var countEl = document.createElement('div');
                    countEl.className = 'timeline-count';
                    countEl.textContent = '사진 ' + slot.photos.length + '장';
                    contentEl.appendChild(countEl);
                }

                if (slot.lat !== null && slot.lng !== null) {
                    var poiEl = document.createElement('div');
                    poiEl.className = 'timeline-poi';
                    contentEl.appendChild(poiEl);
                    poiTasks.push({ el: poiEl, lat: slot.lat, lng: slot.lng });
                }

                if (slot.photos.length) {
                    var photosEl = document.createElement('div');
                    photosEl.className = 'timeline-photos';
                    slot.photos.forEach(function (p) {
                        if (!p.thumbnail_url) { return; }
                        var img = document.createElement('img');
                        img.src = p.thumbnail_url;
                        img.alt = '';
                        img.title = p.taken_at;
                        pendingImgs.push(img);
                        photosEl.appendChild(img);
                    });
                    if (photosEl.children.length) { contentEl.appendChild(photosEl); }
                }

                var memoWrapEl = document.createElement('div');
                memoWrapEl.className = 'timeline-memo';

                var memoInputEl = document.createElement('input');
                memoInputEl.type = 'text';
                memoInputEl.maxLength = 500;
                memoInputEl.placeholder = '이 시간에 한 일을 메모해보세요';
                memoInputEl.value = slot.memo || '';
                memoWrapEl.appendChild(memoInputEl);

                var memoSaveEl = document.createElement('button');
                memoSaveEl.type = 'button';
                memoSaveEl.className = 'note-save-btn';
                memoSaveEl.textContent = '저장';
                memoSaveEl.addEventListener('click', function () {
                    saveDayTimelineNote('time-note', {
                        date: date,
                        slot: slot.slot,
                        memo: memoInputEl.value.trim()
                    }, memoSaveEl, function () {
                        var cached = timelineCache[date];
                        if (!cached) { return; }
                        var target = cached.slots.filter(function (s) { return s.slot === slot.slot; })[0];
                        if (target) { target.memo = memoInputEl.value.trim(); }
                    });
                });
                memoWrapEl.appendChild(memoSaveEl);

                contentEl.appendChild(memoWrapEl);
                rowEl.appendChild(contentEl);
                return rowEl;
            }

            // 반환한 Promise 로 호출측이 순차 실행을 제어한다(병렬 호출 금지 — 세션 잠금·외부 API 부하).
            function loadPoi(poiEl, lat, lng) {
                poiEl.textContent = '주변 정보 불러오는 중…';
                return fetch(timelineUrl + '/poi?lat=' + encodeURIComponent(lat) + '&lng=' + encodeURIComponent(lng), {
                    headers: { Accept: 'application/json' }
                })
                    .then(function (res) {
                        if (!res.ok) { throw new Error('poi fetch failed'); }
                        return res.json();
                    })
                    .then(function (data) {
                        var places = data.places || [];
                        if (!places.length) { poiEl.remove(); return; }
                        var names = places.slice(0, 4).map(function (p) { return p.name; });
                        poiEl.textContent = '📍 주변: ' + names.join(' · ');
                    })
                    .catch(function () { poiEl.remove(); });
            }

            // 이미지들이 로드(또는 실패)될 때까지 기다리되, capMs 를 넘기면 그냥 진행한다.
            function whenImagesSettled(imgs, capMs) {
                var waits = imgs.map(function (img) {
                    return new Promise(function (resolve) {
                        if (img.complete) { resolve(); return; }
                        img.addEventListener('load', resolve, { once: true });
                        img.addEventListener('error', resolve, { once: true });
                    });
                });
                var cap = new Promise(function (resolve) { setTimeout(resolve, capMs); });
                return Promise.race([Promise.all(waits), cap]);
            }

            // 노트/메모 저장 공통 처리 — 버튼에 저장 중/완료/실패 피드백을 준다.
            // map.php 의 postNote 와 달리, 성공 시 로컬 캐시(timelineCache)를 갱신하는
            // onSuccess 콜백을 받는다 — 아코디언을 접었다 다시 열 때 재조회 없이 최신값을 보여주기 위함.
            function saveDayTimelineNote(kind, fields, buttonEl, onSuccess) {
                buttonEl.disabled = true;
                buttonEl.textContent = '저장 중…';

                fetch(timelineUrl + '/' + kind, {
                    method: 'POST',
                    headers: { Accept: 'application/json' },
                    body: new URLSearchParams(fields)
                })
                    .then(function (res) {
                        if (!res.ok) { throw new Error('save failed'); }
                        buttonEl.textContent = '저장됨';
                        onSuccess();
                    })
                    .catch(function () {
                        buttonEl.textContent = '저장 실패';
                    })
                    .then(function () {
                        setTimeout(function () {
                            buttonEl.disabled = false;
                            buttonEl.textContent = '저장';
                        }, 1500);
                    });
            }
```

- [ ] **Step 7: GREEN 확인**

Run: `vendor/bin/phpunit --no-coverage --filter testShowRendersPageShell tests/feature/TripControllerTest.php`
Expected: `OK (1 test, 3 assertions)`

- [ ] **Step 8: 전체 테스트·정적분석 확인**

Run: `composer ci`
Expected: `[OK] No errors`(PHPStan — PHP 파일만 대상이라 JS/CSS 변경은 영향 없음), CS Fixer diff 없음, PHPUnit 전체 `OK (...)`.

- [ ] **Step 9: 커밋**

```bash
git add app/Controllers/TripController.php app/Views/trip-detail.php tests/feature/TripControllerTest.php
git commit -m "✨ feat: 여행 상세에 날짜별 시간표 인라인 펼치기 추가"
```

---

## Task 2: 브라우저 실측 검증

**Files:** 없음(검증 전용 태스크)

**Interfaces:** 없음.

- [ ] **Step 1: 로컬 SQLite 임시 DB로 런타임 구동 확인**

이 프로젝트는 `.env`를 임시로 SQLite3 로 바꿔 `php spark migrate` 후
`mcp__Claude_Browser__preview_start`(`.claude/launch.json`의 `iter-serve`)로 실제 화면을
확인하는 방식을 반복 사용해왔다. `app.baseURL` 은 **기존 활성 라인을 직접 수정**해야 한다
(파일 끝에 새 줄을 추가하면 앞쪽의 기존 활성 라인이 먼저 읽혀 무시된다 — 이전 세션에서
반복 확인된 함정).

```bash
cp .env .env.bak-inline-timeline-verify
python3 - << 'EOF'
p = '.env'
s = open(p).read().replace(
    "app.baseURL = 'http://localhost:8080/'",
    "app.baseURL = 'http://localhost:8299/'",
)
s += "\n# TEMP inline-timeline-verify\ndatabase.default.database = dev-inline-timeline.db\ndatabase.default.DBDriver = SQLite3\ndatabase.default.DBPrefix =\n"
open(p, 'w').write(s)
EOF
php spark migrate
```

Expected: 기존 마이그레이션들이 재실행되고 `Migrations complete.`(이번 태스크는 신규
마이그레이션이 없다 — 이미 존재하는 스키마에 대해 안전하게 재적용됨).

- [ ] **Step 2: 시드 데이터 준비(같은 날짜에 서로 다른 두 시간대 사진)**

```bash
mkdir -p writable/uploads/thumbnails
php -r '
$colors = [[220,60,60],[60,140,220]];
foreach ($colors as $i => $c) {
    $im = imagecreatetruecolor(300, 200);
    imagefill($im, 0, 0, imagecolorallocate($im, $c[0], $c[1], $c[2]));
    imagejpeg($im, "writable/uploads/thumbnails/verify-{$i}.jpg");
}'
sqlite3 writable/dev-inline-timeline.db << EOF
INSERT INTO users (google_sub, email, name, created_at, updated_at) VALUES ('dev-sub', 'dev@example.com', 'Dev', datetime('now'), datetime('now'));
INSERT INTO photo_locations (user_id, source_item_id, lat, lng, thumbnail_path, taken_at, created_at) VALUES
 (1, 'p1', 37.5796, 126.9770, '$(pwd)/writable/uploads/thumbnails/verify-0.jpg', '2024-03-15 00:10:00', datetime('now')),
 (1, 'p2', 37.5665, 126.9780, '$(pwd)/writable/uploads/thumbnails/verify-1.jpg', '2024-03-15 06:00:00', datetime('now'));
INSERT INTO trips (user_id, title, body, start_date, end_date, cover_photo_id, created_at, updated_at) VALUES
 (1, '서울 여행', '고궁 투어', '2024-03-15', '2024-03-15', NULL, datetime('now'), datetime('now'));
EOF
```

Expected: 오류 없이 완료(경복궁·시청 부근 좌표 2장이 3/15 하루에 서로 다른 시간대로 들어감,
여행 1건 저장).

- [ ] **Step 3: 브라우저로 확인**

`mcp__Claude_Browser__preview_start`(`name: "iter-serve"`)로 서버를 띄우고, 새로 생긴
세션 파일에 `user_id|i:1;`을 주입한 뒤 `/trips/1`에 접속해 다음을 확인한다:

1. "포함된 날짜" 목록에 `2024-03-15` 항목이 있고, 옆에 "시간표 보기" 버튼이 있다(이전
   "이 날 시간표 보기" 링크가 사라졌는지 확인 — `mcp__Claude_Browser__read_page` 로 `<a>`
   태그가 day-list 안에 없는지 확인).
2. "시간표 보기" 클릭 → 그 자리 아래 패널이 펼쳐지고, 버튼 텍스트가 "접기"로 바뀐다.
3. 패널 안에 "이 날의 제목"/"이 날의 메모" 입력칸(날짜 노트 카드)과, 두 시간대 행(각각 사진
   1장 + 주변 업장 텍스트, 로드 완료까지 몇 초 대기)이 보인다(`mcp__Claude_Browser__read_network_requests`
   로 `GET /timeline/2024-03-15` → `GET /thumbnails/*` → `GET /timeline/poi?...` 순서 확인 —
   사진이 POI보다 먼저 요청·완료돼야 한다).
4. 시간대 메모 입력 후 "저장" 클릭 → 버튼이 "저장 중…" → "저장됨" → "저장"으로 돌아온다.
   `POST /timeline/time-note` 요청이 200으로 나가는지 확인.
5. "접기" 클릭 → 패널이 닫히고 버튼이 "시간표 보기"로 돌아온다. 다시 "시간표 보기" 클릭 →
   방금 저장한 메모가 그대로 남아 있다(재조회 없이 캐시로 즉시 표시 — Step 3에서 기록한
   `GET /timeline/2024-03-15` 요청이 두 번째 열 때는 **다시 나가지 않아야** 한다).
6. (두 번째 날짜가 있다면) 다른 날짜의 "시간표 보기"를 누르면 방금 열려 있던 패널이 자동으로
   닫히는지 확인(아코디언).

Expected: 위 6개 동작이 화면·네트워크 요청으로 모두 확인됨.

- [ ] **Step 4: 환경 원복**

```bash
mv .env.bak-inline-timeline-verify .env
rm -f writable/dev-inline-timeline.db writable/uploads/thumbnails/verify-*.jpg
rm -f writable/session/ci_session*
```

Expected: `.env`가 원래 상태로 복원되고, 임시 DB·썸네일·세션 파일이 모두 제거됨.

- [ ] **Step 5: 최종 확인**

Run: `git status --short`
Expected: 추적되지 않은 `.claude/`(세션 전용, 무시)를 제외하고 워킹트리 깨끗 — 모든 변경은
Task 1에서 이미 커밋됨.

이 태스크는 커밋할 코드 변경이 없다(검증 전용).

---

## Self-Review

**스펙 커버리지 확인:**
- 인라인 펼치기(아코디언, 한 번에 하나만) → Task 1 Step 6 `toggleDayTimeline()`. ✅
- 날짜 노트 표시·수정 → Task 1 Step 6 `renderDayTimelinePanel()`(날짜 노트 카드). ✅
- 시간대별 행(시각·사진·POI·메모) → Task 1 Step 6 `buildDayTimelineSlot()`. ✅
- 메모 인라인 저장 → Task 1 Step 6 `saveDayTimelineNote()` + 캐시 갱신. ✅
- 사진 확대 뷰어 제외(범위 밖) → `buildDayTimelineSlot()`에 클릭 핸들러 없음(썸네일만). ✅
- "이 날 시간표 보기" 링크를 토글로 교체 → Task 1 Step 6 `renderDayList()`(`<a>` 없음, `<button class="day-timeline-toggle">`만). ✅
- 캐시 재사용(재조회 방지) → Task 1 Step 6 `timelineCache`. ✅
- 에러 처리(fetch 실패·POI 실패·저장 실패) → Task 1 Step 6 각 함수의 `.catch()`. ✅
- 레이트리밋 버킷 공유(신규 버킷 없음) → 신규 라우트가 없으므로 자동 충족(변경 없음). ✅
- 테스트 전략(마크업 존재 확인 + 브라우저 실측) → Task 1 Step 1(테스트), Task 2(브라우저). ✅

**플레이스홀더 스캔:** "TODO"/"TBD"/"적절히 처리" 없음. 모든 스텝에 완전한 코드 첨부.

**타입 일관성 확인:** `saveDayTimelineNote(kind, fields, buttonEl, onSuccess)`의 `onSuccess`
콜백 시그니처가 `day-note` 호출부(`timelineCache[date].day_note = {...}`)와 `time-note`
호출부(`cached.slots` 배열 갱신) 양쪽에서 인자 없이 클로저로 값을 캡처하는 동일한 패턴으로
쓰인다 — 일관됨. `renderDayTimelinePanel(panelEl, date, data)`가 받는 `data` 형태
(`day_note`/`slots`)는 `GET /timeline/{date}`의 기존 응답 형태 그대로이며 `buildDayTimelineSlot`
이 소비하는 `slot.slot`/`slot.label`/`slot.lat`/`slot.lng`/`slot.photos`/`slot.memo` 필드명과
정확히 일치한다(기존 `TimelineService`/`TimelineController`를 변경하지 않았으므로 당연히
일치 — 재확인 완료).
