# 동선 애니메이션 재생 구현 계획

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 메인 지도에서 날짜 선택 시 그 날의 동선을 마커 이동 + 경로 점진 그려짐 애니메이션으로 재생한다(재생/일시정지/배속 1x·2x·4x).

**Architecture:** 백엔드 변경 없음. 재생 계산(하버사인·구간 시간 배분·보간)은 DOM/Leaflet 비의존 순수 함수로 `public/assets/playback-core.js`에 분리해 node 로 검증하고, rAF 기반 재생 엔진·컨트�롤 바는 기존 패턴대로 `app/Views/map.php` 인라인 스크립트에 추가한다.

**Tech Stack:** Leaflet 1.9.4 기본 API(`L.polyline`, `L.circleMarker`), `requestAnimationFrame`, 순수 JS(ES5 스타일 — 기존 map.php 와 동일하게 `var`·function 선언 사용).

## Global Constraints

- 설계 문서: `docs/superpowers/specs/2026-07-23-route-playback-design.md` — 이 계획의 근거.
- 모든 응답·주석은 한국어. 커밋 메시지는 `이모지 + Conventional Commits + 한국어`.
- 작업 브랜치: `feature/route-playback` (origin/dev 기준). 이 모노레포는 PR 없이 `feature → dev` 직접 머지.
- 모든 경로는 `Iter/` 프로젝트 루트 기준 (저장소 루트는 `varius/`, 커밋 경로엔 `Iter/` 프리픽스가 붙음).
- 외부 라이브러리·플러그인 추가 금지. JS 는 기존 map.php 스타일(ES5, 인라인) 유지.
- 재생 시간 1x 기준 15,000ms 고정. 배속 단계 [1, 2, 4]. 프레임 델타 상한 100ms. 펄스 매칭 반경 30m.
- PHP 변경이 없으므로 PHPUnit 테스트 추가 대상 없음 — JS 순수 함수는 node 스크립트(`tests/js/playback-core.test.js`)로 검증, UI 는 브라우저 수동 검증(프로젝트 규칙).

---

### Task 0: 작업 브랜치 생성

**Files:** 없음 (git 조작만)

- [ ] **Step 1: dev 최신화 후 feature 브랜치 생성**

```bash
cd /Users/jongwonbyun/claude-works/varius/Iter
git checkout dev && git pull origin dev
git checkout -b feature/route-playback
```

Expected: `'feature/route-playback' 브랜치로 전환` (신규 생성)

---

### Task 1: 재생 핵심 순수 함수 (playback-core.js)

**Files:**
- Create: `public/assets/playback-core.js`
- Test: `tests/js/playback-core.test.js` (plain node — 테스트 러너 없음)

**Interfaces:**
- Produces (전역 함수 3개 — Task 4·5 의 인라인 엔진이 사용):
  - `haversineMeters(a, b)` — `a`,`b`: `[lat, lng]` 배열. 반환: 미터(number).
  - `buildPlaybackPlan(latlngs, durationMs)` — `latlngs`: `[lat, lng][]`. 반환: `{ segments: [{from, to, dist, startMs, durMs}], totalMs }` 또는 유효 구간이 없으면 `null`. 거리 0 구간은 제외, `durMs`는 거리 비례 배분.
  - `playbackPositionAt(plan, elapsedMs)` — 반환: `{ lat, lng, segIndex, done }`. 끝을 넘으면 `done: true` + 마지막 좌표.

- [ ] **Step 1: 실패하는 node 테스트 작성**

`tests/js/playback-core.test.js`:

```js
// playback-core.js 순수 함수 검증 — 실행: node tests/js/playback-core.test.js
// 테스트 러너 없이 assert 만 사용한다(이 프로젝트는 JS 테스트 인프라를 두지 않는다).
'use strict';

const fs = require('fs');
const path = require('path');
const assert = require('assert');

// 브라우저 전역 스크립트라 require 불가 — 소스를 읽어 전역 스코프에 로드한다.
// 주의: 이 파일이 strict 모드라 직접 eval 은 선언이 eval 스코프에 갇힌다 → 간접 eval 로 전역에 정의.
(0, eval)(fs.readFileSync(path.join(__dirname, '../../public/assets/playback-core.js'), 'utf8'));

// ── haversineMeters ──
// 위도 1도 ≈ 111,195m (R=6371km 기준 πR/180)
const oneDegLat = haversineMeters([0, 0], [1, 0]);
assert.ok(Math.abs(oneDegLat - 111195) < 100, '위도 1도 거리: ' + oneDegLat);
assert.strictEqual(haversineMeters([37.5, 127], [37.5, 127]), 0, '동일 지점은 0m');

// ── buildPlaybackPlan ──
// A(0,0) → B(0.001,0) ≈ 111.2m → B(중복, 스킵) → C(0.003,0) ≈ 222.4m
const pts = [[0, 0], [0.001, 0], [0.001, 0], [0.003, 0]];
const plan = buildPlaybackPlan(pts, 15000);
assert.strictEqual(plan.segments.length, 2, '거리 0 구간은 제외');
assert.ok(Math.abs(plan.segments[0].durMs - 5000) < 1, '1/3 거리 → 5000ms: ' + plan.segments[0].durMs);
assert.ok(Math.abs(plan.segments[1].durMs - 10000) < 1, '2/3 거리 → 10000ms');
assert.strictEqual(plan.segments[0].startMs, 0);
assert.ok(Math.abs(plan.segments[1].startMs - 5000) < 1);
assert.strictEqual(plan.totalMs, 15000);

// 유효 구간 없음 → null
assert.strictEqual(buildPlaybackPlan([[0, 0], [0, 0]], 15000), null, '전부 같은 지점이면 null');
assert.strictEqual(buildPlaybackPlan([[0, 0]], 15000), null, '좌표 1개면 null');
assert.strictEqual(buildPlaybackPlan([], 15000), null, '빈 배열이면 null');

// ── playbackPositionAt ──
const mid = playbackPositionAt(plan, 2500); // 구간 0 의 중간
assert.strictEqual(mid.segIndex, 0);
assert.strictEqual(mid.done, false);
assert.ok(Math.abs(mid.lat - 0.0005) < 1e-9, '구간 0 중간 lat: ' + mid.lat);

const inSeg1 = playbackPositionAt(plan, 10000); // 구간 1 의 중간(5000+10000/2)
assert.strictEqual(inSeg1.segIndex, 1);
assert.ok(Math.abs(inSeg1.lat - 0.002) < 1e-9, '구간 1 중간 lat: ' + inSeg1.lat);

const over = playbackPositionAt(plan, 99999); // 끝 초과
assert.strictEqual(over.done, true);
assert.strictEqual(over.lat, 0.003);
assert.strictEqual(over.segIndex, 1);

const neg = playbackPositionAt(plan, -50); // 음수 방어
assert.strictEqual(neg.lat, 0);

console.log('OK — playback-core 검증 통과');
```

- [ ] **Step 2: 실패 확인**

```bash
node tests/js/playback-core.test.js
```

Expected: FAIL — `ENOENT ... playback-core.js` (파일 없음)

- [ ] **Step 3: 구현 작성**

`public/assets/playback-core.js`:

```js
/**
 * 동선 재생 핵심 계산 — DOM/Leaflet 비의존 순수 함수 모음.
 * map.php 의 재생 엔진과 tests/js/playback-core.test.js 가 함께 사용한다.
 */

// 두 좌표([lat, lng]) 사이 거리(미터) — 하버사인 공식.
function haversineMeters(a, b) {
    var R = 6371000;
    var toRad = Math.PI / 180;
    var dLat = (b[0] - a[0]) * toRad;
    var dLng = (b[1] - a[1]) * toRad;
    var lat1 = a[0] * toRad;
    var lat2 = b[0] * toRad;
    var h = Math.sin(dLat / 2) * Math.sin(dLat / 2)
        + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return 2 * R * Math.asin(Math.sqrt(h));
}

// 하루 좌표열 → 재생 계획. 각 구간 소요시간은 전체 durationMs 를 거리 비례로 배분한다.
// 거리 0 구간(같은 지점 연속 사진)은 제외하고, 유효 구간이 없으면 null 을 반환한다.
function buildPlaybackPlan(latlngs, durationMs) {
    var segments = [];
    var totalDist = 0;
    for (var i = 1; i < latlngs.length; i++) {
        var dist = haversineMeters(latlngs[i - 1], latlngs[i]);
        if (dist <= 0) { continue; }
        segments.push({ from: latlngs[i - 1], to: latlngs[i], dist: dist });
        totalDist += dist;
    }
    if (!segments.length) { return null; }

    var elapsed = 0;
    segments.forEach(function (seg) {
        seg.startMs = elapsed;
        seg.durMs = durationMs * (seg.dist / totalDist);
        elapsed += seg.durMs;
    });
    return { segments: segments, totalMs: durationMs };
}

// 경과 시간(ms) → 현재 좌표(선형 보간)·구간 인덱스. 끝을 넘으면 done: true 와 마지막 좌표.
function playbackPositionAt(plan, elapsedMs) {
    var segments = plan.segments;
    for (var i = 0; i < segments.length; i++) {
        var seg = segments[i];
        if (elapsedMs < seg.startMs + seg.durMs) {
            var ratio = Math.max(0, (elapsedMs - seg.startMs) / seg.durMs);
            return {
                lat: seg.from[0] + (seg.to[0] - seg.from[0]) * ratio,
                lng: seg.from[1] + (seg.to[1] - seg.from[1]) * ratio,
                segIndex: i,
                done: false
            };
        }
    }
    var last = segments[segments.length - 1];
    return { lat: last.to[0], lng: last.to[1], segIndex: segments.length - 1, done: true };
}
```

- [ ] **Step 4: 테스트 통과 확인**

```bash
node tests/js/playback-core.test.js
```

Expected: `OK — playback-core 검증 통과`

- [ ] **Step 5: 커밋**

```bash
git add public/assets/playback-core.js tests/js/playback-core.test.js
git commit -m "✨ feat: 동선 재생 핵심 계산 순수 함수 추가 (playback-core)"
```

---

### Task 2: dateIndex 확장 — 정적 폴리라인·클러스터 마커 참조 저장

**Files:**
- Modify: `app/Views/map.php` — `render()` 내부(현재 500~547행 부근)와 `<script>` 로드부(현재 286~289행 부근)

**Interfaces:**
- Consumes: 없음 (기존 코드만 수정)
- Produces (Task 3·4·5 가 사용): `dateIndex[date]` 구조가 다음으로 확장됨 —
  `{ latlngs: [lat,lng][], firstClusterIndex: number|null, polyline: L.Polyline|null, clusterMarkers: [{lat, lng, marker: L.CircleMarker}] }`

- [ ] **Step 1: playback-core.js 로드 태그 추가**

Leaflet `<script>` 태그(현재 286~289행) 바로 아래, 인라인 `<script>` 위에 추가:

```html
    <script src="/assets/playback-core.js"></script>
```

- [ ] **Step 2: render() 에서 폴리라인·클러스터 마커 참조 수집**

`render()` 안의 폴리라인 생성부(현재 510~513행)를 다음으로 교체 — 생성된 폴리라인을 변수에 담는다:

```js
                    // 경로선 — 같은 날짜의 이동 순서. 재생 시 흐리게 처리할 수 있도록 참조를 보관한다.
                    var dayPolyline = null;
                    if (latlngs.length > 1) {
                        dayPolyline = L.polyline(latlngs, { color: group.color, weight: 3, opacity: 0.8 }).addTo(map);
                    }
```

클러스터 forEach 직전(현재 515행 `var firstClusterIndex = null;` 다음)에 수집 배열 추가:

```js
                    var clusterMarkers = []; // 재생 시 펄스 강조용 — 이 날짜의 클러스터 마커 목록
```

클러스터 forEach 안, `registryEntry.marker = L.circleMarker(...)` 와 mouseover 바인딩 다음에 추가:

```js
                        clusterMarkers.push({ lat: c.lat, lng: c.lng, marker: registryEntry.marker });
```

`dateIndex[group.date] = ...` (현재 540행)를 다음으로 교체:

```js
                    dateIndex[group.date] = {
                        latlngs: latlngs,
                        firstClusterIndex: firstClusterIndex,
                        polyline: dayPolyline,
                        clusterMarkers: clusterMarkers
                    };
```

- [ ] **Step 3: 브라우저 회귀 확인**

개발 서버를 띄우고(`php spark serve` 또는 기존 launch 설정) 지도 페이지 접속 →
날짜 클릭 시 기존과 동일하게 지도 이동·팝업이 열리는지, 콘솔 에러가 없는지 확인.
콘솔에서 `typeof buildPlaybackPlan` 입력 시 `"function"` 이 나오는지 확인.

- [ ] **Step 4: 커밋**

```bash
git add app/Views/map.php
git commit -m "♻️ refactor: dateIndex 에 폴리라인·클러스터 마커 참조 보관 (재생 준비)"
```

---

### Task 3: 재생 컨트롤 바 UI

**Files:**
- Modify: `app/Views/map.php` — `<style>` 끝부분(현재 216행 `</style>` 직전), `#empty` div(현재 227행) 다음, 인라인 스크립트의 `selectDay()`(현재 479~493행)

**Interfaces:**
- Consumes: Task 1 `buildPlaybackPlan`, Task 2 `dateIndex[date]` 확장 구조
- Produces (Task 4 가 사용):
  - DOM: `#playback-bar`(hidden 토글), `#playback-date`, `#playback-toggle`, `#playback-speed`
  - JS: `preparePlayback(date)` — 날짜 선택 시 호출, 재생 가능하면 바 표시. `stopPlayback()` — 재생 상태 전체 정리(이 Task 에선 뼈대만).
  - 상태: `var playback` (null = 재생 없음), 상수 `PLAYBACK_DURATION_MS`, `PLAYBACK_SPEEDS`, `PLAYBACK_FRAME_CAP_MS`, `PULSE_RADIUS_METERS`

- [ ] **Step 1: CSS 추가** — `</style>` 직전(사진 확대 뷰어 블록 다음)에:

```css
        /* ── 동선 재생 컨트롤 ── */
        #playback-bar {
            position: absolute; left: 296px; bottom: 16px; z-index: 1000;
            display: flex; align-items: center; gap: 8px;
            background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
            padding: 8px 12px; font-size: 13px;
        }
        #playback-bar[hidden] { display: none; }
        #playback-date { color: #555; }
        #playback-toggle {
            border: none; border-radius: 6px; background: #1a73e8; color: #fff;
            padding: 6px 12px; cursor: pointer; font-size: 13px;
        }
        #playback-speed {
            border: 1px solid #ccc; border-radius: 6px; background: #fff;
            padding: 5px 10px; cursor: pointer; font-size: 13px; color: #333;
        }
```

- [ ] **Step 2: HTML 추가** — `#empty` div 바로 다음, `#map-container` 안에:

```html
        <div id="playback-bar" hidden>
            <span id="playback-date"></span>
            <button type="button" id="playback-toggle">▶ 재생</button>
            <button type="button" id="playback-speed" title="재생 속도">1x</button>
        </div>
```

- [ ] **Step 3: 상태·준비 함수 추가** — 인라인 스크립트에서 `selectDay()` 정의 아래에:

```js
            // ── 동선 재생 ─────────────────────────────────────
            var PLAYBACK_DURATION_MS = 15000; // 1x 기준 하루 전체 재생 시간
            var PLAYBACK_SPEEDS = [1, 2, 4];
            var PLAYBACK_FRAME_CAP_MS = 100;  // 탭 복귀 시 순간이동 방지용 프레임 델타 상한
            var PULSE_RADIUS_METERS = 30;     // 펄스 매칭 반경(클러스터 묶음 반경과 동일)

            var playbackBarEl = document.getElementById('playback-bar');
            var playbackDateEl = document.getElementById('playback-date');
            var playbackToggleEl = document.getElementById('playback-toggle');
            var playbackSpeedEl = document.getElementById('playback-speed');

            var playback = null; // 진행 중인 재생 상태 — null 이면 재생 없음

            // 날짜 선택 시 호출 — 기존 재생을 정리하고, 재생 가능한 날이면 컨트롤 바를 띄운다.
            function preparePlayback(date) {
                stopPlayback();
                var entry = dateIndex[date];
                var plan = entry ? buildPlaybackPlan(entry.latlngs, PLAYBACK_DURATION_MS) : null;
                if (!plan) { playbackBarEl.hidden = true; return; }

                playback = {
                    date: date, entry: entry, plan: plan,
                    elapsed: 0, speedIndex: 0, playing: false, rafId: null, lastTs: null,
                    line: null, marker: null, pulsedMarkers: []
                };
                playbackDateEl.textContent = date;
                playbackToggleEl.textContent = '▶ 재생';
                playbackSpeedEl.textContent = '1x';
                playbackBarEl.hidden = false;
            }

            // 재생 레이어·상태를 모두 정리하고 정적 경로선을 원복한다.
            function stopPlayback() {
                if (!playback) { return; }
                if (playback.rafId !== null) { cancelAnimationFrame(playback.rafId); }
                if (playback.line) { map.removeLayer(playback.line); }
                if (playback.marker) { map.removeLayer(playback.marker); }
                if (playback.entry.polyline) { playback.entry.polyline.setStyle({ opacity: 0.8 }); }
                playback = null;
            }
```

- [ ] **Step 4: selectDay 연동** — `selectDay()` 마지막 줄(`itemEl.classList.add('active');`) 다음에 추가:

```js
                preparePlayback(itemEl.dataset.date);
```

- [ ] **Step 5: 브라우저 확인**

- 좌표 2개 이상인 날짜 클릭 → 지도 좌하단에 `날짜 · ▶ 재생 · 1x` 바 표시.
- 좌표 1개뿐인 날짜(있다면) 클릭 → 바 미표시.
- 다른 날짜 클릭 → 바의 날짜가 갱신됨. 콘솔 에러 없음.

- [ ] **Step 6: 커밋**

```bash
git add app/Views/map.php
git commit -m "✨ feat: 지도에 동선 재생 컨트롤 바 추가"
```

---

### Task 4: 재생 엔진 (rAF 루프·재생/일시정지·배속)

**Files:**
- Modify: `app/Views/map.php` — Task 3 에서 추가한 재생 블록 바로 아래

**Interfaces:**
- Consumes: Task 1 `playbackPositionAt`, Task 3 의 `playback` 상태·DOM 요소·상수
- Produces: `startPlayback()`, `pausePlayback()`, `resetPlaybackProgress()`, `playbackFrame(ts)` — Task 5 는 `playbackFrame` 안의 펄스 훅만 추가

- [ ] **Step 1: 버튼 핸들러·엔진 함수 추가** — Task 3 의 `stopPlayback()` 정의 아래에:

```js
            playbackToggleEl.addEventListener('click', function () {
                if (!playback) { return; }
                if (playback.playing) { pausePlayback(); return; }
                if (playback.elapsed >= playback.plan.totalMs) { resetPlaybackProgress(); }
                startPlayback();
            });

            playbackSpeedEl.addEventListener('click', function () {
                if (!playback) { return; }
                playback.speedIndex = (playback.speedIndex + 1) % PLAYBACK_SPEEDS.length;
                playbackSpeedEl.textContent = PLAYBACK_SPEEDS[playback.speedIndex] + 'x';
            });

            function startPlayback() {
                var entry = playback.entry;
                if (!playback.line) {
                    var color = entry.polyline ? entry.polyline.options.color : '#1a73e8';
                    if (entry.polyline) { entry.polyline.setStyle({ opacity: 0.25 }); }
                    playback.line = L.polyline([playback.plan.segments[0].from], {
                        color: color, weight: 5, opacity: 1
                    }).addTo(map);
                    playback.marker = L.circleMarker(playback.plan.segments[0].from, {
                        radius: 8, color: '#fff', weight: 2, fillColor: color, fillOpacity: 1
                    }).addTo(map);
                }
                playback.playing = true;
                playback.lastTs = null; // 일시정지 후 재개 시 델타 점프 방지
                playbackToggleEl.textContent = '⏸ 일시정지';
                playback.rafId = requestAnimationFrame(playbackFrame);
            }

            function pausePlayback() {
                playback.playing = false;
                if (playback.rafId !== null) { cancelAnimationFrame(playback.rafId); playback.rafId = null; }
                playbackToggleEl.textContent = '▶ 재생';
            }

            // 끝까지 재생한 뒤 "다시 재생"을 위해 진행 상태만 초기화한다(레이어는 유지).
            function resetPlaybackProgress() {
                playback.elapsed = 0;
                playback.pulsedMarkers = [];
                if (playback.line) { playback.line.setLatLngs([playback.plan.segments[0].from]); }
            }

            function playbackFrame(ts) {
                if (!playback || !playback.playing) { return; }
                if (playback.lastTs !== null) {
                    // 탭 비활성화 복귀 시 큰 델타로 순간이동하지 않도록 상한을 건다.
                    var delta = Math.min(ts - playback.lastTs, PLAYBACK_FRAME_CAP_MS);
                    playback.elapsed += delta * PLAYBACK_SPEEDS[playback.speedIndex];
                }
                playback.lastTs = ts;

                var pos = playbackPositionAt(playback.plan, playback.elapsed);
                var segs = playback.plan.segments;

                // 진행선 = 지나온 구간 시작점들 + 현재 위치
                var lineLatLngs = [];
                for (var i = 0; i <= pos.segIndex; i++) { lineLatLngs.push(segs[i].from); }
                lineLatLngs.push([pos.lat, pos.lng]);
                playback.line.setLatLngs(lineLatLngs);
                playback.marker.setLatLng([pos.lat, pos.lng]);

                if (pos.done) {
                    playback.playing = false;
                    playback.rafId = null;
                    playbackToggleEl.textContent = '▶ 다시 재생';
                    return;
                }
                playback.rafId = requestAnimationFrame(playbackFrame);
            }
```

- [ ] **Step 2: 브라우저 확인**

- ▶ 클릭 → 정적 선이 흐려지고, 굵은 진행선이 그려지며 마커가 이동. 약 15초 뒤 끝 도달, 버튼이 `▶ 다시 재생` 으로 변경.
- 재생 중 ⏸ → 멈춤, 다시 ▶ → 이어서 재생(점프 없음).
- 배속 2x/4x → 체감 속도 변경.
- 재생 중 다른 날짜 클릭 → 진행선·마커 제거, 이전 날짜 정적 선 원복, 새 날짜 바 표시.
- `▶ 다시 재생` 클릭 → 처음부터 재생.
- 재생 중 다른 탭 갔다가 복귀 → 순간이동 없이 이어짐.

- [ ] **Step 3: 커밋**

```bash
git add app/Views/map.php
git commit -m "✨ feat: 동선 재생 엔진 추가 (rAF 이동·경로 그려짐·일시정지·배속)"
```

---

### Task 5: 사진 클러스터 펄스 강조

**Files:**
- Modify: `app/Views/map.php` — Task 4 의 `playbackFrame()` 및 그 아래

**Interfaces:**
- Consumes: Task 1 `haversineMeters`, Task 2 `entry.clusterMarkers`, Task 4 `playbackFrame`
- Produces: `pulseClusterNear(latlng)` — 내부 전용

- [ ] **Step 1: 펄스 함수 추가** — `playbackFrame()` 정의 아래에:

```js
            // 도달 지점 30m 이내의 클러스터 마커를 잠깐 확대했다 원복한다 — 마커당 1회만.
            // (클러스터는 30m 반경으로 묶이므로 경로 지점과 좌표가 정확히 일치하지 않을 수 있다.)
            function pulseClusterNear(latlng) {
                playback.entry.clusterMarkers.forEach(function (cm) {
                    if (playback.pulsedMarkers.indexOf(cm.marker) !== -1) { return; }
                    if (haversineMeters(latlng, [cm.lat, cm.lng]) > PULSE_RADIUS_METERS) { return; }
                    playback.pulsedMarkers.push(cm.marker);
                    cm.marker.setRadius(11);
                    setTimeout(function () { cm.marker.setRadius(6); }, 450);
                });
            }
```

- [ ] **Step 2: playbackFrame 에 펄스 훅 추가** — `playback.marker.setLatLng(...)` 줄과 `if (pos.done)` 사이에:

```js
                // 지나친 구간 경계(사진 지점)마다 근처 클러스터 펄스 — pulsedMarkers 가 중복을 막는다.
                for (var j = 0; j < pos.segIndex; j++) { pulseClusterNear(segs[j].to); }
                if (pos.done) { pulseClusterNear(segs[segs.length - 1].to); }
```

주의: `if (pos.done)` 종료 블록은 Task 4 코드 그대로 두고, 그 **앞에** 위 두 줄을 넣는다
(종료 프레임에서도 마지막 지점 펄스가 실행되도록 `pos.done` 체크가 두 번 나타나는 게 맞다).

- [ ] **Step 3: 브라우저 확인**

- 재생 중 마커가 사진 클러스터 지점을 지날 때 해당 원형 마커가 잠깐 커졌다 돌아옴.
- 같은 클러스터를 여러 번 지나도 펄스는 1회만.
- `▶ 다시 재생` 시 펄스가 다시 발생(재생마다 초기화).

- [ ] **Step 4: 커밋**

```bash
git add app/Views/map.php
git commit -m "✨ feat: 재생 중 사진 클러스터 지점 펄스 강조"
```

---

### Task 6: 통합 검증 및 dev 머지

**Files:** 없음 (검증·git 조작만)

- [ ] **Step 1: node 검증 재실행**

```bash
node tests/js/playback-core.test.js
```

Expected: `OK — playback-core 검증 통과`

- [ ] **Step 2: composer ci 회귀 확인** (PHP 변경 없음 — 통과만 확인)

```bash
composer ci
```

Expected: CS Fixer·PHPStan·PHPUnit 모두 통과

- [ ] **Step 3: 브라우저 통합 시나리오 최종 점검**

Task 3~5 의 확인 항목 전체를 한 번에 재점검 + 사이드바 "시간표" 버튼·마커 팝업 "더보기" 등 기존 기능 회귀 없음 확인.

- [ ] **Step 4: dev 머지·푸시** (이 모노레포는 PR 없이 직접 머지)

```bash
git checkout dev && git pull origin dev
git merge --no-ff feature/route-playback -m "🔀 merge: 동선 애니메이션 재생 기능"
git push origin dev
git branch -d feature/route-playback
```

- [ ] **Step 5: 배포가 필요하면 dev → main 머지** (사용자 확인 후)

```bash
git checkout main && git pull origin main
git merge dev -m "🔀 merge: dev → main 배포 (동선 애니메이션 재생)"
git push origin main && git checkout dev
```
