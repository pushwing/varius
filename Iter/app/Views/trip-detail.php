<?php

declare(strict_types=1);

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
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>여행 상세 — Iter</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="stylesheet" href="/assets/nav.css">
    <style>
        html, body { margin: 0; font-family: system-ui, sans-serif; color: #222; }
        main { padding: 40px 20px; max-width: 720px; margin: 0 auto; }
        .header-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; }
        .header-row h1 { font-size: 20px; margin: 0; }
        .header-actions { display: flex; gap: 8px; position: relative; }
        .btn {
            display: inline-block; padding: 7px 14px; border-radius: 8px; border: 1.5px solid transparent;
            background: #1a73e8; color: #fff; font-size: 13px; font-weight: 600; cursor: pointer;
        }
        .btn:disabled { background: #9db8e8; cursor: not-allowed; }
        .btn-secondary { background: #fff; color: #1a73e8; border-color: #c7d2e0; }
        .btn-danger { background: #fff; color: #c0392b; border-color: #e6b8b3; }
        .field-group {
            background: #fff; border: 1px solid #eee; border-radius: 12px; padding: 18px; margin-bottom: 20px;
        }
        .field-group label { display: block; font-size: 12px; color: #666; margin-bottom: 4px; }
        .field-group input, .field-group textarea {
            width: 100%; box-sizing: border-box; border: 1px solid #ccd6f0; border-radius: 6px;
            font: inherit; padding: 7px 10px; margin-bottom: 12px;
        }
        .date-row { display: flex; gap: 12px; }
        .date-row > div { flex: 1; }
        .cover-picker { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
        .cover-option { position: relative; cursor: pointer; }
        .cover-option img { width: 72px; height: 72px; object-fit: cover; border-radius: 8px; display: block; border: 3px solid transparent; }
        .cover-option.selected img { border-color: #1a73e8; }
        .day-list { list-style: none; padding: 0; margin: 0; }
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
        .save-feedback { font-size: 12px; color: #777; margin-left: 8px; }
        #share-menu {
            position: absolute; top: calc(100% + 6px); right: 0; z-index: 10;
            background: #fff; border: 1px solid #e2e2e2; border-radius: 10px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.12); padding: 6px; min-width: 140px;
        }
        .share-option {
            display: block; width: 100%; text-align: left; border: none; background: none;
            padding: 8px 10px; font-size: 13px; color: #333; cursor: pointer; border-radius: 6px;
        }
        .share-option:hover { background: #f4f6fb; }
    </style>
</head>
<body>
    <?= view('partials/nav', ['uploadUrl' => $uploadUrl, 'mapUrl' => $mapUrl, 'tripsUrl' => $tripsUrl, 'logoutUrl' => $logoutUrl]) ?>
    <main data-trips-url="<?= esc($tripsUrl, 'attr') ?>" data-trip-id="<?= (int) $tripId ?>" data-timeline-url="<?= esc($timelineUrl, 'attr') ?>">
        <div class="header-row">
            <h1 id="trip-title-heading">여행</h1>
            <div class="header-actions">
                <button type="button" class="btn btn-secondary" id="share-toggle">🔗 공유</button>
                <div id="share-menu" hidden>
                    <button type="button" class="share-option" data-share="x">X(트위터)</button>
                    <button type="button" class="share-option" data-share="facebook">페이스북</button>
                    <button type="button" class="share-option" data-share="kakao">카카오톡</button>
                    <button type="button" class="share-option" data-share="instagram">인스타그램</button>
                    <button type="button" class="share-option" data-share="copy">링크 복사</button>
                </div>
                <button type="button" class="btn btn-danger" id="delete-btn">삭제</button>
            </div>
        </div>

        <div class="field-group">
            <label for="trip-title">제목</label>
            <input type="text" id="trip-title" maxlength="100">

            <label for="trip-body">설명</label>
            <textarea id="trip-body" maxlength="2000" rows="3"></textarea>

            <div class="date-row">
                <div>
                    <label for="trip-start">시작일</label>
                    <input type="date" id="trip-start">
                </div>
                <div>
                    <label for="trip-end">종료일</label>
                    <input type="date" id="trip-end">
                </div>
            </div>

            <label>커버 사진</label>
            <div class="cover-picker" id="cover-picker"></div>

            <button type="button" class="btn" id="save-btn">저장</button>
            <span class="save-feedback" id="save-feedback"></span>
        </div>

        <div class="field-group">
            <label>포함된 날짜</label>
            <ul class="day-list" id="day-list"></ul>
        </div>
    </main>

    <script>
        (function () {
            var mainEl = document.querySelector('main');
            var tripsUrl = mainEl.dataset.tripsUrl;
            var tripId = mainEl.dataset.tripId;
            var tripUrl = tripsUrl + '/' + tripId;

            var titleEl = document.getElementById('trip-title');
            var bodyEl = document.getElementById('trip-body');
            var startEl = document.getElementById('trip-start');
            var endEl = document.getElementById('trip-end');
            var coverPickerEl = document.getElementById('cover-picker');
            var dayListEl = document.getElementById('day-list');
            var headingEl = document.getElementById('trip-title-heading');
            var saveFeedbackEl = document.getElementById('save-feedback');
            var selectedCoverId = null;

            fetch(tripUrl + '/data', { headers: { Accept: 'application/json' } })
                .then(function (res) { return res.json(); })
                .then(render)
                .catch(function () { headingEl.textContent = '여행을 불러오지 못했습니다.'; });

            function render(data) {
                var trip = data.trip;
                headingEl.textContent = trip.title;
                titleEl.value = trip.title;
                bodyEl.value = trip.body;
                startEl.value = trip.start_date;
                endEl.value = trip.end_date;
                selectedCoverId = trip.cover_photo_id;

                renderCoverPicker(data.days);
                renderDayList(data.days);
            }

            function renderCoverPicker(days) {
                coverPickerEl.innerHTML = '';
                days.forEach(function (day) {
                    if (!day.first_thumbnail_url) { return; }

                    var optionEl = document.createElement('div');
                    optionEl.className = 'cover-option' + (day.first_photo_id === selectedCoverId ? ' selected' : '');
                    optionEl.dataset.photoId = day.first_photo_id;

                    var img = document.createElement('img');
                    img.src = day.first_thumbnail_url;
                    img.alt = day.date;
                    img.title = day.date;
                    optionEl.appendChild(img);

                    optionEl.addEventListener('click', function () {
                        selectedCoverId = day.first_photo_id;
                        coverPickerEl.querySelectorAll('.cover-option').forEach(function (el) { el.classList.remove('selected'); });
                        optionEl.classList.add('selected');
                    });

                    coverPickerEl.appendChild(optionEl);
                });
            }

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

            document.getElementById('save-btn').addEventListener('click', function () {
                var btn = this;
                btn.disabled = true;
                saveFeedbackEl.textContent = '저장 중…';

                var fields = {
                    title: titleEl.value.trim(),
                    body: bodyEl.value.trim(),
                    start_date: startEl.value,
                    end_date: endEl.value
                };
                if (selectedCoverId) { fields.cover_photo_id = String(selectedCoverId); }

                fetch(tripUrl + '/update', {
                    method: 'POST',
                    headers: { Accept: 'application/json' },
                    body: new URLSearchParams(fields)
                })
                    .then(function (res) {
                        if (!res.ok) { return res.json().then(function (b) { throw new Error(b.error || '저장 실패'); }); }
                        return res.json();
                    })
                    .then(function () {
                        headingEl.textContent = fields.title;
                        saveFeedbackEl.textContent = '저장됨';
                    })
                    .catch(function (err) {
                        saveFeedbackEl.textContent = err.message || '저장 실패';
                    })
                    .then(function () {
                        btn.disabled = false;
                        setTimeout(function () { saveFeedbackEl.textContent = ''; }, 2000);
                    });
            });

            document.getElementById('delete-btn').addEventListener('click', function () {
                if (!window.confirm('이 여행을 삭제할까요? 사진·시간표는 그대로 남고, 여행 그룹만 해제됩니다.')) { return; }

                fetch(tripUrl + '/delete', { method: 'POST', headers: { Accept: 'application/json' } })
                    .then(function (res) {
                        if (!res.ok) { throw new Error('delete failed'); }
                        window.location.href = tripsUrl;
                    })
                    .catch(function () { alert('삭제에 실패했습니다.'); });
            });

            // ── SNS 공유(시간표 공유 메뉴와 동일 패턴, 대상만 여행 단위) ──

            var shareToggleEl = document.getElementById('share-toggle');
            var shareMenuEl = document.getElementById('share-menu');
            var shareUrlCache = null;

            shareToggleEl.addEventListener('click', function (evt) {
                evt.stopPropagation();
                shareMenuEl.hidden = !shareMenuEl.hidden;
            });
            document.addEventListener('click', function (evt) {
                if (!shareMenuEl.hidden && !evt.target.closest('#share-toggle') && !evt.target.closest('#share-menu')) {
                    shareMenuEl.hidden = true;
                }
            });
            shareMenuEl.addEventListener('click', function (evt) {
                var btn = evt.target.closest('.share-option');
                if (btn) { handleShareOption(btn.dataset.share); }
            });

            function getShareUrl() {
                if (shareUrlCache) { return Promise.resolve(shareUrlCache); }

                return fetch(tripUrl + '/share', { method: 'POST', headers: { Accept: 'application/json' } })
                    .then(function (res) {
                        if (!res.ok) { throw new Error('공유 링크 생성 실패'); }
                        return res.json();
                    })
                    .then(function (data) {
                        shareUrlCache = data.url;
                        return data.url;
                    });
            }

            function handleShareOption(kind) {
                getShareUrl().then(function (url) {
                    var title = headingEl.textContent;

                    if (kind === 'x') {
                        window.open('https://twitter.com/intent/tweet?url=' + encodeURIComponent(url) + '&text=' + encodeURIComponent(title), '_blank', 'noopener,width=560,height=480');
                    } else if (kind === 'facebook') {
                        window.open('https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(url), '_blank', 'noopener,width=560,height=480');
                    } else if (kind === 'kakao' || kind === 'instagram') {
                        if (navigator.share) {
                            navigator.share({ title: title, url: url }).catch(function () {});
                        } else {
                            copyToClipboard(url);
                            alert((kind === 'kakao' ? '카카오톡은' : '인스타그램은') + ' 이 브라우저에서 바로 공유할 수 없어 링크를 복사했어요. 앱에 붙여넣어 공유해보세요.');
                        }
                    } else if (kind === 'copy') {
                        copyToClipboard(url);
                        alert('링크가 복사되었습니다.');
                    }

                    shareMenuEl.hidden = true;
                }).catch(function () {
                    alert('공유 링크를 만들지 못했습니다.');
                });
            }

            function copyToClipboard(text) {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text);
                    return;
                }
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); } catch (e) { /* 무시 */ }
                document.body.removeChild(ta);
            }
        })();
    </script>
</body>
</html>
