# 지도 목록 메뉴(월별/일별) 사이드 패널 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `/map` 페이지에 월별→일별 목록을 상시 노출하는 좌측 사이드 패널을 추가해, 일자를 클릭하면 지도가 그 날짜 위치로 이동하고 사진 레이어가 자동으로 열리게 한다.

**Architecture:** 백엔드(`RouteController`, `RouteVisualizationService`, `/routes` JSON API)는 변경하지 않는다. `app/Views/map.php` 하나의 인라인 `<style>`/`<script>`만 확장하는 순수 프론트엔드 변경이다. `/routes` 응답이 이미 날짜별 `points`(전체 좌표)·`clusters`(장소별 사진 묶음)를 담고 있으므로, 월별 그룹핑·정렬은 클라이언트에서 구성한다.

**Tech Stack:** PHP 8.2+ / CodeIgniter 4 (view 템플릿만), Leaflet.js 1.9.4, 순수 vanilla JS(빌드 스텝 없음).

## Global Constraints

- 변경 파일은 `app/Views/map.php` 하나뿐이다 — 새 JS/CSS 파일을 추가하지 않는다(이 프로젝트엔 빌드 스텝이 없고 뷰 하나에 스타일·스크립트를 인라인으로 두는 기존 패턴을 따른다).
- 백엔드(`RouteController`, `RouteVisualizationService`, `/routes` API 응답 스키마)는 변경하지 않는다.
- 우상단 `#legend`는 완전히 제거하고 좌측 사이드 패널로 대체한다(스펙 확정 사항, 병존 없음).
- 목록 정렬은 최신 우선(역순): 최근 월이 위, 그 달 안에서도 최근 일자가 위.
- 월 헤더 토글은 독립적(다른 월에 영향 없음). 기본 펼침 상태는 가장 최근 월만 펼침.
- 일자 클릭 시: 그 날짜 전체 좌표로 `fitBounds` + 그 날짜의 **첫 번째 클러스터**로 `openLayer` 호출 + 클릭한 항목만 활성 표시.
- 이 저장소(varius 모노레포)는 PR 없이 `feature/* → dev` 직접 머지 정책이다(리뷰 절차만 생략, 브랜치 전략은 유지). 커밋 메시지는 이모지 + Conventional Commits + 한국어.
- 로컬 개발 환경에 DB(`photo_locations`)·Google OAuth 자격증명이 설정돼 있지 않아(`.env`의 `database.*` 항목이 모두 주석 처리됨, 확인 완료) 실제 `/map` 라우트를 브라우저로 완주하는 건 불가능하다. 대신 스크래치패드에 **fetch를 스텁 처리한 정적 HTML 하네스**를 만들어 매 태스크마다 `app/Views/map.php`와 동일한 `<style>`/`<script>` 내용을 복사해 넣고 브라우저로 시각 확인한다. 하네스는 저장소에 커밋하지 않는다.
- **(실행 중 갱신)** Task 1 실행 시점에 `origin/dev`에 내비게이션 바 병합분(`feature/persistent-nav`)이 먼저 들어와 있어 `dev`를 병합했다. 그 결과 `app/Views/map.php`에 `<div id="map-container">` 래퍼가 새로 생겼고 `#map`/`#legend`/`#empty`가 그 안에 중첩됐다(상단 `<nav>` 아래로 지도 영역을 배치하기 위함). 인라인 `<script>` 블록 내용은 이 병합으로 전혀 바뀌지 않았다 — Task 2·4·5의 스크립트 관련 old_string/new_string은 원래 계획 그대로 유효하다. **Task 2의 하네스 마크업과 Task 3의 body 마크업 교체 지점만** 이 새 `#map-container` 래퍼를 반영하도록 아래에서 갱신했다.
- **(실행 중 갱신)** Browser 프리뷰 도구는 프로젝트 폴더 밖의 `file://` 경로(스크래치패드 포함)를 상호작용 불가능한 정적 스냅샷으로만 연다는 게 Task 2 실행 중 확인됐다. 그래서 하네스 파일은 스크래치패드 대신 **프로젝트 루트의 `Iter/_map-harness.html`**(커밋 대상 아님, `git add` 시 이 파일명을 포함하지 않도록 매번 확인)에 두고, `.claude/launch.json`에 정적 서버 설정(`static-harness-serve`, `php -S localhost:8300 -t .`, `.claude/`도 커밋 대상 아님)을 추가해 `preview_start`로 띄운 뒤 `http://localhost:8300/_map-harness.html`로 접속해 확인한다. 이하 태스크의 "하네스" 경로는 모두 이 경로를 가리킨다.

---

## Task 1: 작업 브랜치 준비

**Files:** 없음(git 브랜치 작업만).

- [ ] **Step 1: dev 최신화 후 feature 브랜치 생성**

```bash
git checkout dev
git pull origin dev
git checkout -b feature/map-route-list-sidebar
```

- [ ] **Step 2: 브랜치 확인**

```bash
git branch --show-current
```

Expected: `feature/map-route-list-sidebar`

---

## Task 2: 검증 하네스 준비 + 기준선(baseline) 확인

이후 모든 태스크는 이 하네스에 변경 내용을 동기화해 브라우저로 검증한다. 이 태스크에서는 **현재(변경 전) `app/Views/map.php`** 내용을 그대로 옮겨 하네스 자체가 신뢰할 수 있는지부터 확인한다.

**Files:**
- Create: `Iter/_map-harness.html`(프로젝트 루트, 커밋 안 함 — Browser 프리뷰 도구가 프로젝트 폴더 밖 파일은 상호작용 불가능한 정적 스냅샷으로만 열기 때문에 스크래치패드 대신 여기 둔다)
- Create: `.claude/launch.json`에 `static-harness-serve` 설정 추가(이미 커밋 대상 아닌 `.claude/` 디렉터리)

**Interfaces:**
- Produces: 하네스 HTML — 이후 태스크에서 `<style>`·`<script>` 블록만 교체해가며 재사용한다. 목(mock) 데이터 스키마는 `/routes` API와 동일한 `{ dates: [{ date, color, points, clusters }] }` 형태를 그대로 따른다.

- [ ] **Step 1: 하네스 파일 작성**

`app/Views/map.php`의 현재 `<head>`~`</html>` 내용을 그대로 옮기되 (a) PHP 태그를 제거하고 `data-routes-url`을 리터럴 문자열로 바꾸고, (b) leaflet.js 스크립트 태그와 메인 인라인 스크립트 사이에 `fetch`를 스텁 처리하는 스크립트를 끼워 넣는다. 아래 전체 내용으로 파일을 생성한다.

```html
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iter — 동선 지도 (하네스)</title>
    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
        crossorigin="">
    <style>
        /* public/assets/nav.css 인라인 사본 — file:// 로 여는 하네스는 절대경로 /assets/nav.css 를 못 불러오므로 직접 포함한다. */
        nav {
            display: flex; gap: 16px; padding: 12px 20px; border-bottom: 1px solid #ddd;
            align-items: center; font-family: system-ui, sans-serif;
        }
        nav a { color: #222; text-decoration: none; font-size: 14px; }
        nav a:hover { text-decoration: underline; }
        nav .brand { display: inline-flex; }
        nav .brand img { height: 24px; }
        nav .spacer { flex: 1; }
        nav .legal { display: flex; gap: 16px; }
        nav .legal a { color: #777; font-size: 13px; }

        html, body { margin: 0; height: 100%; font-family: system-ui, sans-serif; }
        body { display: flex; flex-direction: column; }
        #map-container { position: relative; flex: 1; min-height: 0; }
        #map { position: absolute; inset: 0; }
        #legend {
            position: absolute; top: 12px; right: 12px; z-index: 1000;
            background: rgba(255, 255, 255, 0.92); padding: 10px 12px;
            border-radius: 8px; box-shadow: 0 1px 6px rgba(0, 0, 0, 0.3);
            max-height: 60%; overflow-y: auto; font-size: 13px;
        }
        #legend h4 { margin: 0 0 6px; font-size: 13px; }
        .legend-row { display: flex; align-items: center; gap: 6px; margin: 3px 0; }
        .legend-swatch { width: 12px; height: 12px; border-radius: 2px; flex: none; }
        #empty {
            position: absolute; inset: 0; display: none; z-index: 1000;
            align-items: center; justify-content: center;
            font-size: 15px; color: #555; background: #f4f4f4;
        }
        .popup-more-btn {
            margin-top: 4px; padding: 5px 12px; font-size: 13px; border: none;
            border-radius: 6px; background: #1a73e8; color: #fff; cursor: pointer;
        }
        #photo-layer {
            position: fixed; inset: 0; z-index: 2000; background: rgba(0, 0, 0, 0.75);
            display: flex; align-items: center; justify-content: center; padding: 20px;
        }
        #photo-layer[hidden] { display: none; }
        #photo-layer-panel {
            background: #fff; border-radius: 10px; max-width: 720px; width: 100%;
            max-height: 85vh; display: flex; flex-direction: column; overflow: hidden;
        }
        #photo-layer-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 18px; border-bottom: 1px solid #eee;
        }
        #photo-layer-header h3 { margin: 0; font-size: 15px; }
        #photo-layer-close {
            border: none; background: none; font-size: 20px; cursor: pointer; color: #666; line-height: 1;
        }
        #photo-layer-grid {
            padding: 14px 18px; overflow-y: auto;
            display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px;
        }
        #photo-layer-grid img { width: 100%; border-radius: 8px; display: block; object-fit: cover; }
    </style>
</head>
<body>
    <nav>
        <a href="#" class="brand"><img src="https://placehold.co/24x24?text=I" alt="Iter"></a>
        <a href="#">홈</a>
        <a href="#">지도 보기</a>
        <a href="#">로그아웃</a>
        <span class="spacer"></span>
        <span class="legal">
            <a href="#">개인정보처리방침</a>
            <a href="#">서비스 이용약관</a>
        </span>
    </nav>
    <div id="map-container">
        <div id="map" data-routes-url="/mock-routes"></div>
        <div id="legend" hidden><h4>날짜별 동선</h4><div id="legend-body"></div></div>
        <div id="empty">표시할 동선이 없습니다. 사진을 선택해 좌표를 적재하세요.</div>
    </div>

    <div id="photo-layer" hidden>
        <div id="photo-layer-panel">
            <div id="photo-layer-header">
                <h3 id="photo-layer-title"></h3>
                <button type="button" id="photo-layer-close" aria-label="닫기">&times;</button>
            </div>
            <div id="photo-layer-grid"></div>
        </div>
    </div>

    <script
        src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""></script>
    <script>
        // 하네스 전용 — /routes 응답과 동일한 스키마의 목 데이터로 fetch 를 스텁 처리한다.
        // 2026-06(2일, 그중 하루는 클러스터 2개)·2026-07(1일) 두 달로 구성해
        // "최신 월만 기본 펼침"·"다중 클러스터 일자는 첫 클러스터만 오픈" 동작을 모두 검증한다.
        var MOCK_ROUTES = {
            dates: [
                {
                    date: '2026-06-05',
                    color: '#e6194b',
                    points: [
                        { lat: 37.5665, lng: 126.9780, taken_at: '2026-06-05 09:00:00', media_item_id: 'm1', thumbnail_url: 'https://placehold.co/150x150?text=1' },
                        { lat: 37.5666, lng: 126.9781, taken_at: '2026-06-05 09:05:00', media_item_id: 'm2', thumbnail_url: 'https://placehold.co/150x150?text=2' }
                    ],
                    clusters: [
                        { lat: 37.5665, lng: 126.9780, photos: [
                            { media_item_id: 'm1', taken_at: '2026-06-05 09:00:00', thumbnail_url: 'https://placehold.co/150x150?text=1' },
                            { media_item_id: 'm2', taken_at: '2026-06-05 09:05:00', thumbnail_url: 'https://placehold.co/150x150?text=2' }
                        ] }
                    ]
                },
                {
                    date: '2026-06-18',
                    color: '#3cb44b',
                    points: [
                        { lat: 37.5663, lng: 126.9779, taken_at: '2026-06-18 10:00:00', media_item_id: 'm3', thumbnail_url: 'https://placehold.co/150x150?text=3' },
                        { lat: 37.5663, lng: 126.9779, taken_at: '2026-06-18 10:10:00', media_item_id: 'm4', thumbnail_url: 'https://placehold.co/150x150?text=4' },
                        { lat: 37.5663, lng: 126.9779, taken_at: '2026-06-18 10:20:00', media_item_id: 'm5', thumbnail_url: 'https://placehold.co/150x150?text=5' },
                        { lat: 37.5563, lng: 126.9236, taken_at: '2026-06-18 15:00:00', media_item_id: 'm6', thumbnail_url: 'https://placehold.co/150x150?text=6' }
                    ],
                    clusters: [
                        { lat: 37.5663, lng: 126.9779, photos: [
                            { media_item_id: 'm3', taken_at: '2026-06-18 10:00:00', thumbnail_url: 'https://placehold.co/150x150?text=3' },
                            { media_item_id: 'm4', taken_at: '2026-06-18 10:10:00', thumbnail_url: 'https://placehold.co/150x150?text=4' },
                            { media_item_id: 'm5', taken_at: '2026-06-18 10:20:00', thumbnail_url: 'https://placehold.co/150x150?text=5' }
                        ] },
                        { lat: 37.5563, lng: 126.9236, photos: [
                            { media_item_id: 'm6', taken_at: '2026-06-18 15:00:00', thumbnail_url: 'https://placehold.co/150x150?text=6' }
                        ] }
                    ]
                },
                {
                    date: '2026-07-02',
                    color: '#4363d8',
                    points: [
                        { lat: 35.1796, lng: 129.0756, taken_at: '2026-07-02 08:00:00', media_item_id: 'm7', thumbnail_url: 'https://placehold.co/150x150?text=7' }
                    ],
                    clusters: [
                        { lat: 35.1796, lng: 129.0756, photos: [
                            { media_item_id: 'm7', taken_at: '2026-07-02 08:00:00', thumbnail_url: 'https://placehold.co/150x150?text=7' }
                        ] }
                    ]
                }
            ]
        };

        window.fetch = function () {
            return Promise.resolve({ json: function () { return Promise.resolve(MOCK_ROUTES); } });
        };
    </script>
    <script>
        (function () {
            var mapEl = document.getElementById('map');
            var map = L.map('map').setView([37.5665, 126.9780], 11);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(map);

            var clusterRegistry = []; // 인덱스 → { date, photos } — 팝업의 "더보기" 클릭 시 조회용.

            var layerEl = document.getElementById('photo-layer');
            var layerTitleEl = document.getElementById('photo-layer-title');
            var layerGridEl = document.getElementById('photo-layer-grid');

            document.getElementById('photo-layer-close').addEventListener('click', closeLayer);
            layerEl.addEventListener('click', function (evt) {
                if (evt.target === layerEl) { closeLayer(); }
            });

            // 팝업은 매번 새로 DOM 에 그려지므로 이벤트 위임으로 "더보기" 클릭을 잡는다.
            document.body.addEventListener('click', function (evt) {
                var btn = evt.target.closest('.popup-more-btn');
                if (btn) { openLayer(Number(btn.dataset.clusterIndex)); }
            });

            fetch(mapEl.dataset.routesUrl, { headers: { Accept: 'application/json' } })
                .then(function (res) { return res.json(); })
                .then(function (data) { render(data.dates || []); })
                .catch(function () { showEmpty(); });

            function render(dates) {
                if (!dates.length) { showEmpty(); return; }

                var bounds = [];
                var legendBody = document.getElementById('legend-body');

                dates.forEach(function (group) {
                    var latlngs = group.points.map(function (p) { return [p.lat, p.lng]; });
                    bounds = bounds.concat(latlngs);

                    if (latlngs.length > 1) {
                        L.polyline(latlngs, { color: group.color, weight: 3, opacity: 0.8 }).addTo(map);
                    }

                    (group.clusters || []).forEach(function (c) {
                        var clusterIndex = clusterRegistry.length;
                        clusterRegistry.push({ date: group.date, photos: c.photos });

                        var popupHtml = '<div style="font-size:12px;color:#333;">' +
                            group.date + ' · ' + c.photos.length + '장</div>' +
                            '<button type="button" class="popup-more-btn" data-cluster-index="' + clusterIndex + '">더보기</button>';

                        L.circleMarker([c.lat, c.lng], {
                            radius: 6, color: group.color, fillColor: group.color, fillOpacity: 0.9
                        }).addTo(map).bindPopup(popupHtml, { maxWidth: 180 });
                    });

                    var row = document.createElement('div');
                    row.className = 'legend-row';
                    row.innerHTML = '<span class="legend-swatch" style="background:' + group.color +
                        '"></span><span>' + group.date + ' (' + group.points.length + ')</span>';
                    legendBody.appendChild(row);
                });

                document.getElementById('legend').hidden = false;
                if (bounds.length) { map.fitBounds(bounds, { padding: [40, 40] }); }
            }

            function openLayer(clusterIndex) {
                var cluster = clusterRegistry[clusterIndex];
                if (!cluster) { return; }

                layerTitleEl.textContent = cluster.date + ' · ' + cluster.photos.length + '장';
                layerGridEl.innerHTML = '';
                cluster.photos.forEach(function (p) {
                    if (!p.thumbnail_url) { return; }
                    var img = document.createElement('img');
                    img.src = p.thumbnail_url;
                    img.alt = '';
                    img.title = p.taken_at;
                    layerGridEl.appendChild(img);
                });

                layerEl.hidden = false;
            }

            function closeLayer() {
                layerEl.hidden = true;
            }

            function showEmpty() {
                document.getElementById('empty').style.display = 'flex';
            }
        })();
    </script>
</body>
</html>
```

- [ ] **Step 2: 기준선(baseline) 브라우저 확인**

`preview_start`로 `static-harness-serve`(`php -S localhost:8300 -t .`)를 띄운 뒤 `http://localhost:8300/_map-harness.html` 을 연다.

Expected:
- 상단에 내비게이션 바(홈·지도 보기·로그아웃 링크)가 보이고, 그 아래 `#map-container`가 나머지 화면 높이를 채운다(지도가 내비게이션 바를 가리거나 그 뒤로 밀려 들어가지 않는다).
- 지도가 서울/부산 좌표 쪽으로 확대되어 표시된다(3개 날짜의 bounds).
- 우상단에 `#legend`가 "2026-06-05 (2)", "2026-06-18 (4)", "2026-07-02 (1)" 3행으로 보인다.
- 지도 위 원형 마커가 총 4개(06-05 1개, 06-18 2개, 07-02 1개) 보인다.
- 06-18의 서울시청 근처 마커를 클릭 → 팝업의 "더보기" 클릭 → 사진 레이어가 열리고 3장 썸네일이 보인다.

이 결과가 확인되면 하네스가 실제 `map.php` 로직을 정확히 재현한다는 뜻이므로, 이후 태스크는 이 파일의 `<style>`/`<script>`만 갱신하며 진행한다. 커밋 없음(스크래치패드 파일은 저장소 밖).

---

## Task 3: 레이아웃 변경 — legend 제거, 사이드 패널 스캐폴드 추가

**Files:**
- Modify: `app/Views/map.php` (스타일 블록, body 마크업)
- Sync: 스크래치패드 하네스(Task 2에서 만든 파일)의 동일 블록

**Interfaces:**
- Produces: `#route-sidebar`(컨테이너) / `#route-sidebar-body`(월·일 트리가 채워질 자리) — Task 4가 이 id로 DOM을 채운다. `#map`의 `left` 오프셋(`280px`)은 이후 태스크에서 변경하지 않는다.

- [ ] **Step 1: `app/Views/map.php`의 `<style>` 블록에서 legend 관련 규칙을 사이드바 규칙으로 교체**

`app/Views/map.php:34-42`의 아래 블록을 찾는다.

```css
        #legend {
            position: absolute; top: 12px; right: 12px; z-index: 1000;
            background: rgba(255, 255, 255, 0.92); padding: 10px 12px;
            border-radius: 8px; box-shadow: 0 1px 6px rgba(0, 0, 0, 0.3);
            max-height: 60%; overflow-y: auto; font-size: 13px;
        }
        #legend h4 { margin: 0 0 6px; font-size: 13px; }
        .legend-row { display: flex; align-items: center; gap: 6px; margin: 3px 0; }
        .legend-swatch { width: 12px; height: 12px; border-radius: 2px; flex: none; }
```

다음으로 교체한다.

```css
        #route-sidebar {
            position: absolute; top: 0; left: 0; bottom: 0; width: 280px; z-index: 1000;
            background: #fff; box-shadow: 2px 0 6px rgba(0, 0, 0, 0.15);
            overflow-y: auto; font-size: 13px;
        }
        #route-sidebar-header {
            padding: 14px 16px; font-weight: 600; font-size: 14px; border-bottom: 1px solid #eee;
        }
        .month-group { border-bottom: 1px solid #eee; }
        .month-header {
            display: block; width: 100%; text-align: left; padding: 10px 16px;
            border: none; background: #f7f7f7; cursor: pointer; font-size: 13px; font-weight: 600;
        }
        .month-header:hover { background: #eee; }
        .day-list[hidden] { display: none; }
        .day-item {
            display: block; width: 100%; text-align: left; padding: 8px 16px 8px 24px;
            border: none; background: none; cursor: pointer; font-size: 13px; color: #333;
        }
        .day-item:hover { background: #f0f4ff; }
        .day-item.active { background: #e3edff; font-weight: 600; }
```

또한 같은 블록 위에 있는 `#map` 규칙(`app/Views/map.php:33`)도 사이드바 폭만큼 좌측을 밀어내도록 바꾼다. (`#map`의 컨테이닝 블록은 `#map-container`다 — 내비게이션 바 병합으로 새로 생긴 `position: relative; flex: 1;` 래퍼로, 내비 바 아래 남은 영역 전체를 차지한다. `#map`을 그 안에서 `left: 280px`로 밀어내면 자동으로 내비 바 아래·사이드바 옆 영역만 차지하게 된다.)

```css
        #map { position: absolute; inset: 0; }
```

다음으로 교체한다.

```css
        #map { position: absolute; top: 0; right: 0; bottom: 0; left: 280px; }
```

- [ ] **Step 2: body 마크업에서 legend div를 사이드바로 교체**

`app/Views/map.php:78-81`의 아래 블록을 찾는다(내비게이션 바 병합으로 `#map`/`#legend`/`#empty`가 `#map-container` 안에 중첩돼 있다).

```html
    <div id="map-container">
        <div id="map" data-routes-url="<?= esc($routesUrl, 'attr') ?>"></div>
        <div id="legend" hidden><h4>날짜별 동선</h4><div id="legend-body"></div></div>
        <div id="empty">표시할 동선이 없습니다. 사진을 선택해 좌표를 적재하세요.</div>
    </div>
```

다음으로 교체한다.

```html
    <div id="map-container">
        <div id="route-sidebar">
            <div id="route-sidebar-header">동선 목록</div>
            <div id="route-sidebar-body"></div>
        </div>
        <div id="map" data-routes-url="<?= esc($routesUrl, 'attr') ?>"></div>
        <div id="empty">표시할 동선이 없습니다. 사진을 선택해 좌표를 적재하세요.</div>
    </div>
```

- [ ] **Step 3: 인라인 스크립트의 legend 참조 제거(임시)**

이 태스크는 레이아웃만 다루므로, `render()` 안에서 `legendBody`/`#legend` 를 참조하던 부분을 지금 지워서 존재하지 않는 엘리먼트를 참조하는 에러를 막는다. `app/Views/map.php`의 `render()` 함수 안, 아래 4곳을 수정한다.

`var legendBody = document.getElementById('legend-body');` 줄을 삭제한다.

`dates.forEach` 콜백 마지막의 아래 블록을 삭제한다.

```javascript
                    var row = document.createElement('div');
                    row.className = 'legend-row';
                    row.innerHTML = '<span class="legend-swatch" style="background:' + group.color +
                        '"></span><span>' + group.date + ' (' + group.points.length + ')</span>';
                    legendBody.appendChild(row);
```

`document.getElementById('legend').hidden = false;` 줄을 삭제한다(바로 아래 `if (bounds.length) { map.fitBounds(...) }` 줄은 그대로 둔다).

- [ ] **Step 4: 스크래치패드 하네스에 동일 변경 동기화**

Task 2에서 만든 하네스 파일의 `<style>`/body 마크업/`render()` 함수에 Step 1~3과 정확히 동일한 변경을 적용한다(하네스는 `data-routes-url`만 `/mock-routes` 리터럴로 다르고 나머지는 `map.php`와 동일해야 한다).

- [ ] **Step 5: 브라우저로 확인**

하네스를 다시 로드한다(같은 URL이면 새로고침).

Expected:
- 좌측에 흰 배경 사이드 패널이 보이고 상단에 "동선 목록" 헤더만 있다(아직 월/일 목록은 없음 — Task 4에서 채움).
- 우상단 legend는 더 이상 보이지 않는다.
- 지도가 좌측 280px만큼 밀려서 렌더링되고, 3개 날짜의 마커·경로선은 이전과 동일하게 보인다.
- 06-18 마커의 "더보기" 클릭 → 사진 레이어가 여전히 정상 동작한다(회귀 없음).

- [ ] **Step 6: 커밋**

```bash
git add app/Views/map.php
git commit -m "$(cat <<'EOF'
♻️ refactor: 지도 우상단 legend 제거하고 좌측 사이드바 스캐폴드로 교체
EOF
)"
```

---

## Task 4: 월별/일별 데이터 모델 구성 + 사이드바 트리 렌더링

**Files:**
- Modify: `app/Views/map.php` (인라인 스크립트)
- Sync: 스크래치패드 하네스

**Interfaces:**
- Consumes: Task 3이 만든 `#route-sidebar-body` (사이드바 트리를 채워 넣을 컨테이너).
- Produces: 모듈 스코프 변수 `dateIndex`(날짜 문자열 → `{ latlngs, firstClusterIndex }`) — Task 5의 일자 클릭 핸들러가 이 변수로 조회한다. DOM: `.month-group` > `.month-header`(속성 `data-month-key`) + `.day-list` > `.day-item`(속성 `data-date`) — Task 5가 이 클래스/속성으로 이벤트 위임을 건다.

- [ ] **Step 1: `clusterRegistry` 선언 옆에 `dateIndex` 추가**

`app/Views/map.php`에서 아래 줄을 찾는다.

```javascript
            var clusterRegistry = []; // 인덱스 → { date, photos } — 팝업의 "더보기" 클릭 시 조회용.
```

다음으로 교체한다.

```javascript
            var clusterRegistry = []; // 인덱스 → { date, photos } — 팝업의 "더보기" 클릭 시 조회용.
            var dateIndex = {}; // 날짜(YYYY-MM-DD) → { latlngs, firstClusterIndex } — 사이드바 일자 클릭 시 조회용.
```

- [ ] **Step 2: `render()` 안에서 클러스터 순회 시 첫 클러스터 인덱스를 기록하고, `dateIndex` 를 채운 뒤 사이드바를 렌더링하도록 교체**

Task 3 이후 `render()`의 `dates.forEach` 콜백은 아래 형태다(legend 관련 줄이 이미 제거된 상태).

```javascript
                dates.forEach(function (group) {
                    var latlngs = group.points.map(function (p) { return [p.lat, p.lng]; });
                    bounds = bounds.concat(latlngs);

                    if (latlngs.length > 1) {
                        L.polyline(latlngs, { color: group.color, weight: 3, opacity: 0.8 }).addTo(map);
                    }

                    (group.clusters || []).forEach(function (c) {
                        var clusterIndex = clusterRegistry.length;
                        clusterRegistry.push({ date: group.date, photos: c.photos });

                        var popupHtml = '<div style="font-size:12px;color:#333;">' +
                            group.date + ' · ' + c.photos.length + '장</div>' +
                            '<button type="button" class="popup-more-btn" data-cluster-index="' + clusterIndex + '">더보기</button>';

                        L.circleMarker([c.lat, c.lng], {
                            radius: 6, color: group.color, fillColor: group.color, fillOpacity: 0.9
                        }).addTo(map).bindPopup(popupHtml, { maxWidth: 180 });
                    });
                });

                if (bounds.length) { map.fitBounds(bounds, { padding: [40, 40] }); }
```

다음으로 교체한다.

```javascript
                var dateOrder = []; // 화면에 보여줄 날짜 순서(원본 오름차순 유지) — 사이드바 정렬용.

                dates.forEach(function (group) {
                    var latlngs = group.points.map(function (p) { return [p.lat, p.lng]; });
                    bounds = bounds.concat(latlngs);

                    if (latlngs.length > 1) {
                        L.polyline(latlngs, { color: group.color, weight: 3, opacity: 0.8 }).addTo(map);
                    }

                    var firstClusterIndex = null;

                    (group.clusters || []).forEach(function (c) {
                        var clusterIndex = clusterRegistry.length;
                        clusterRegistry.push({ date: group.date, photos: c.photos });
                        if (firstClusterIndex === null) { firstClusterIndex = clusterIndex; }

                        var popupHtml = '<div style="font-size:12px;color:#333;">' +
                            group.date + ' · ' + c.photos.length + '장</div>' +
                            '<button type="button" class="popup-more-btn" data-cluster-index="' + clusterIndex + '">더보기</button>';

                        L.circleMarker([c.lat, c.lng], {
                            radius: 6, color: group.color, fillColor: group.color, fillOpacity: 0.9
                        }).addTo(map).bindPopup(popupHtml, { maxWidth: 180 });
                    });

                    dateIndex[group.date] = { latlngs: latlngs, firstClusterIndex: firstClusterIndex };
                    dateOrder.push({ date: group.date, count: group.points.length });
                });

                renderSidebar(dateOrder);
                if (bounds.length) { map.fitBounds(bounds, { padding: [40, 40] }); }
```

- [ ] **Step 3: `renderSidebar()` 함수 추가**

`render()` 함수가 끝나는 `}` 바로 다음에 새 함수를 추가한다(기존 `openLayer` 함수 정의 앞).

```javascript
            function renderSidebar(dateOrder) {
                var monthMap = {}; // 'YYYY-MM' → list<{date, count}>
                var monthOrder = [];

                dateOrder.forEach(function (entry) {
                    var monthKey = entry.date.slice(0, 7);
                    if (!monthMap[monthKey]) {
                        monthMap[monthKey] = [];
                        monthOrder.push(monthKey);
                    }
                    monthMap[monthKey].push(entry);
                });

                var mostRecentMonthKey = monthOrder[monthOrder.length - 1];
                var bodyEl = document.getElementById('route-sidebar-body');
                bodyEl.innerHTML = '';

                monthOrder.slice().reverse().forEach(function (monthKey) {
                    var days = monthMap[monthKey].slice().reverse();
                    var parts = monthKey.split('-');
                    var label = parts[0] + '년 ' + Number(parts[1]) + '월';

                    var groupEl = document.createElement('div');
                    groupEl.className = 'month-group';

                    var headerEl = document.createElement('button');
                    headerEl.type = 'button';
                    headerEl.className = 'month-header';
                    headerEl.dataset.monthKey = monthKey;
                    headerEl.textContent = label + ' (' + days.length + '일)';

                    var listEl = document.createElement('div');
                    listEl.className = 'day-list';
                    listEl.hidden = monthKey !== mostRecentMonthKey;

                    days.forEach(function (entry) {
                        var monthNum = Number(entry.date.slice(5, 7));
                        var dayNum = Number(entry.date.slice(8, 10));

                        var itemEl = document.createElement('button');
                        itemEl.type = 'button';
                        itemEl.className = 'day-item';
                        itemEl.dataset.date = entry.date;
                        itemEl.textContent = monthNum + '월 ' + dayNum + '일 (' + entry.count + '장)';
                        listEl.appendChild(itemEl);
                    });

                    groupEl.appendChild(headerEl);
                    groupEl.appendChild(listEl);
                    bodyEl.appendChild(groupEl);
                });
            }
```

- [ ] **Step 4: 스크래치패드 하네스에 동일 변경 동기화**

Step 1~3과 정확히 동일한 변경을 하네스 파일의 인라인 스크립트에도 적용한다.

- [ ] **Step 5: 브라우저로 확인**

하네스를 새로고침한 뒤 Browser 프리뷰의 `read_page` 도구로 `#route-sidebar-body` 하위 구조를 읽는다.

Expected:
- 월 그룹이 위에서부터 "2026년 7월 (1일)", "2026년 6월 (2일)" 순서로 보인다(최신 월이 위).
- "2026년 7월" 아래 `.day-list`는 펼쳐진 상태(hidden 아님)로 "7월 2일 (1장)" 항목이 보인다.
- "2026년 6월" 아래 `.day-list`는 `hidden` 속성이 있어 화면에 보이지 않는다(아직 토글 기능은 없으므로 DOM 검사로만 확인).
- `.day-list[hidden]`이 아닌 목록 안 항목 텍스트가 "7월 2일 (1장)" 정확히 일치.

- [ ] **Step 6: 커밋**

```bash
git add app/Views/map.php
git commit -m "$(cat <<'EOF'
✨ feat: 지도 사이드바에 월별/일별 동선 목록 트리 렌더링 추가
EOF
)"
```

---

## Task 5: 인터랙션 — 월 헤더 토글 + 일자 클릭(fitBounds·레이어 오픈·활성 표시)

**Files:**
- Modify: `app/Views/map.php` (인라인 스크립트)
- Sync: 스크래치패드 하네스

**Interfaces:**
- Consumes: Task 4의 `dateIndex`(날짜 → `{ latlngs, firstClusterIndex }`), `.month-header`/`.day-item` DOM 구조, 기존 `openLayer(clusterIndex)` 함수.
- Produces: 없음(이 태스크로 사용자 인터랙션이 완성된다).

- [ ] **Step 1: 기존 이벤트 위임 리스너에 월 헤더·일자 클릭 분기 추가**

`app/Views/map.php`에서 아래 블록을 찾는다.

```javascript
            // 팝업은 매번 새로 DOM 에 그려지므로 이벤트 위임으로 "더보기" 클릭을 잡는다.
            document.body.addEventListener('click', function (evt) {
                var btn = evt.target.closest('.popup-more-btn');
                if (btn) { openLayer(Number(btn.dataset.clusterIndex)); }
            });
```

다음으로 교체한다.

```javascript
            // 팝업/사이드바 모두 매번 새로 DOM 에 그려지므로 이벤트 위임으로 클릭을 잡는다.
            document.body.addEventListener('click', function (evt) {
                var btn = evt.target.closest('.popup-more-btn');
                if (btn) { openLayer(Number(btn.dataset.clusterIndex)); return; }

                var monthHeader = evt.target.closest('.month-header');
                if (monthHeader) { toggleMonth(monthHeader); return; }

                var dayItem = evt.target.closest('.day-item');
                if (dayItem) { selectDay(dayItem); return; }
            });

            function toggleMonth(headerEl) {
                var listEl = headerEl.nextElementSibling;
                listEl.hidden = !listEl.hidden;
            }

            function selectDay(itemEl) {
                var entry = dateIndex[itemEl.dataset.date];
                if (!entry) { return; }

                if (entry.latlngs.length) { map.fitBounds(entry.latlngs, { padding: [40, 40] }); }
                if (entry.firstClusterIndex !== null) { openLayer(entry.firstClusterIndex); }

                document.querySelectorAll('.day-item.active').forEach(function (el) { el.classList.remove('active'); });
                itemEl.classList.add('active');
            }
```

- [ ] **Step 2: 스크래치패드 하네스에 동일 변경 동기화**

Step 1과 정확히 동일한 변경을 하네스 파일에도 적용한다.

- [ ] **Step 3: 브라우저로 월 토글 확인**

하네스를 새로고침한다. Browser 프리뷰의 `computer` 도구로 "2026년 6월 (2일)" 헤더를 클릭한다.

Expected: 클릭 후 `read_page`로 확인 시 "6월 5일 (2장)", "6월 18일 (4장)" 항목이 보이는 상태(`hidden` 해제)로 바뀐다. 이어서 "2026년 7월 (1일)" 헤더를 클릭해도 "7월 2일 (1장)" 항목이 계속 보이는 상태 그대로다(다른 월에 영향 주지 않는지 확인 — 7월 헤더 클릭은 오히려 접어야 정상이므로, 클릭 한 번 더 하면 접히는지도 함께 확인).

- [ ] **Step 4: 브라우저로 일자 클릭 확인**

"6월 18일 (4장)" 항목을 클릭한다.

Expected:
- 지도가 서울(37.5563~37.5663, 126.9236~126.9779 범위)로 fitBounds 되어 두 클러스터 마커가 모두 보이도록 줌/이동한다(부산 마커는 화면 밖으로 밀려남).
- 사진 레이어가 자동으로 열리고 제목이 "2026-06-18 · 3장"으로 표시된다(첫 번째 클러스터 = 서울시청 근처, 사진 3장). 홍대 클러스터(1장)의 사진은 보이지 않는다 — 스펙대로 첫 번째 클러스터만 여는 게 맞다.
- "6월 18일 (4장)" 항목에 활성 배경색이 적용된다. 이어서 "7월 2일 (1장)"을 클릭하면 활성 표시가 그쪽으로 옮겨가고 "6월 18일" 항목의 활성 표시는 사라진다.

- [ ] **Step 5: 커밋**

```bash
git add app/Views/map.php
git commit -m "$(cat <<'EOF'
✨ feat: 사이드바 월 토글·일자 클릭 인터랙션 추가(지도 이동+레이어 오픈)
EOF
)"
```

---

## Task 6: 회귀 확인 + dev 병합

**Files:** 없음(검증·git 작업만).

- [ ] **Step 1: 정적 분석·테스트 회귀 확인**

```bash
composer ci
```

Expected: CS Fixer → PHPStan → PHPUnit 모두 통과(이 변경은 PHP 클래스 로직을 건드리지 않으므로 기존 스위트가 그대로 통과해야 한다. 실패 시 `app/Views/map.php` 편집 과정에서 실수로 PHP 태그(`<?= esc($routesUrl, 'attr') ?>`)를 건드리지 않았는지부터 확인한다).

- [ ] **Step 2: 최종 하네스 스모크 테스트 재확인**

하네스를 다시 로드해 Task 2~5의 Expected 결과가 모두 여전히 유효한지 한 번에 재확인한다(레이아웃, 월 트리, 토글, 일자 클릭 4가지).

- [ ] **Step 3: 빈 데이터(날짜 0개) 상태 확인**

하네스 파일에서 `MOCK_ROUTES`를 일시적으로 `{ dates: [] }`로 바꾸고 새로고침한다.

Expected: `#route-sidebar-body`가 비어 있고(월/일 항목 없음, 헤더 "동선 목록"만 보임), `#empty`("표시할 동선이 없습니다...") 메시지가 화면에 표시된다 — `renderSidebar()`는 `dates.length === 0`일 때 호출되지 않으므로 사이드바가 자동으로 빈 상태를 유지하는지 확인하는 것이다. 확인 후 `MOCK_ROUTES`를 원래 3개 날짜 데이터로 되돌린다(하네스는 저장소 밖 파일이므로 원복해도 커밋 대상 아님).

- [ ] **Step 4: dev로 병합**

이 저장소는 PR 없이 직접 병합한다(varius 모노레포 정책). 이 저장소의 최근 feature→dev 병합 이력(`git log`)은 일반 병합 커밋(`git merge`, non-squash) 방식이므로 동일하게 따른다.

```bash
git checkout dev
git pull origin dev
git merge --no-ff feature/map-route-list-sidebar -m "$(cat <<'EOF'
🔀 merge: 지도 목록 메뉴(월별/일별) 사이드 패널 추가
EOF
)"
git push origin dev
```

- [ ] **Step 5: feature 브랜치 정리**

```bash
git branch -d feature/map-route-list-sidebar
git push origin --delete feature/map-route-list-sidebar
```

Expected: `git branch -a` 에서 `feature/map-route-list-sidebar`(로컬·원격 모두)가 더 이상 보이지 않는다.
