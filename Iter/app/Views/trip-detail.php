<?php

declare(strict_types=1);

/**
 * 여행 상세/편집 — 제목·설명·기간·커버 수정, 포함된 날짜 목록.
 *
 * @var int    $tripId
 * @var string $tripsUrl
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
        .day-list li {
            display: flex; align-items: center; justify-content: space-between;
            padding: 10px 0; border-bottom: 1px solid #f0f0f0; font-size: 13px;
        }
        .day-list a { color: #1a73e8; text-decoration: none; font-size: 12px; }
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
    <main data-trips-url="<?= esc($tripsUrl, 'attr') ?>" data-trip-id="<?= (int) $tripId ?>">
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

            function renderDayList(days) {
                dayListEl.innerHTML = '';
                days.forEach(function (day) {
                    var li = document.createElement('li');

                    var label = document.createElement('span');
                    label.textContent = day.date + ' · 사진 ' + day.photo_count + '장';
                    li.appendChild(label);

                    var link = document.createElement('a');
                    link.href = '/map?date=' + encodeURIComponent(day.date);
                    link.textContent = '이 날 시간표 보기';
                    li.appendChild(link);

                    dayListEl.appendChild(li);
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
