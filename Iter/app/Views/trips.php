<?php

declare(strict_types=1);

/**
 * "내 여행" 목록 — 저장된 여행 + 자동 제안 카드.
 *
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
    <title>내 여행 — Iter</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png">
    <link rel="stylesheet" href="/assets/nav.css">
    <style>
        html, body { margin: 0; font-family: system-ui, sans-serif; color: #222; }
        main { padding: 40px 20px; max-width: 960px; margin: 0 auto; }
        .page-title { font-size: 22px; margin: 0 0 6px; }
        .page-lead { margin: 0 0 24px; font-size: 14px; color: #444; line-height: 1.6; }
        h2.section-title { font-size: 16px; margin: 28px 0 12px; }
        .trip-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px; }
        .trip-card {
            display: block; text-decoration: none; color: inherit;
            background: #fff; border: 1px solid #eee; border-radius: 12px; overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }
        .trip-card-cover { width: 100%; height: 130px; object-fit: cover; background: #f0f0f0; display: block; }
        .trip-card-body { padding: 12px 14px; }
        .trip-card-title { font-size: 14px; font-weight: 600; margin: 0 0 4px; }
        .trip-card-meta { font-size: 12px; color: #777; }
        .suggestion-card { background: #f7f9ff; border: 1px dashed #b9cdfa; }
        .suggestion-form { padding: 10px 14px 14px; display: none; }
        .suggestion-form.open { display: block; }
        .suggestion-form input { width: 100%; box-sizing: border-box; border: 1px solid #ccd6f0; border-radius: 6px; font: inherit; padding: 6px 9px; margin-bottom: 6px; }
        .btn {
            display: inline-block; padding: 6px 14px; border-radius: 8px; border: 1.5px solid transparent;
            background: #1a73e8; color: #fff; font-size: 13px; font-weight: 600; cursor: pointer;
        }
        .btn:disabled { background: #9db8e8; cursor: not-allowed; }
        .empty { color: #777; font-size: 13px; padding: 12px 0; }
        .yearly-summary { font-size: 14px; color: #444; margin: 0 0 12px; }
        .top-spot-card { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
        .top-spot-thumb { width: 48px; height: 48px; object-fit: cover; border-radius: 8px; display: block; }
        .top-spot-thumb[hidden] { display: none; }
        .heatmap-grid {
            display: grid; grid-template-rows: repeat(7, 12px); grid-auto-columns: 12px; grid-auto-flow: column;
            gap: 3px; overflow-x: auto; padding-bottom: 4px;
        }
        .heatmap-cell { width: 12px; height: 12px; border-radius: 2px; background: #ebedf0; }
        .heatmap-cell.active { background: #1a73e8; }
    </style>
</head>
<body>
    <?= view('partials/nav', ['uploadUrl' => $uploadUrl, 'mapUrl' => $mapUrl, 'tripsUrl' => $tripsUrl, 'logoutUrl' => $logoutUrl]) ?>
    <main data-trips-url="<?= esc($tripsUrl, 'attr') ?>">
        <h1 class="page-title">내 여행</h1>
        <p class="page-lead">연속된 날짜를 하나의 여행으로 묶어 커버 사진·기간·사진 수를 한눈에 봅니다.</p>

        <section class="yearly-stats" id="yearly-stats" hidden>
            <h2 class="section-title" id="yearly-stats-title"></h2>
            <p class="yearly-summary" id="yearly-summary"></p>
            <div class="top-spot-card" id="top-spot-card" hidden>
                <img class="top-spot-thumb" id="top-spot-thumb" alt="" hidden>
                <span id="top-spot-label"></span>
            </div>
            <div class="heatmap-grid" id="heatmap-grid"></div>
        </section>

        <h2 class="section-title">저장된 여행</h2>
        <div class="trip-grid" id="saved-trips"></div>
        <div class="empty" id="saved-empty" hidden>아직 저장된 여행이 없습니다.</div>

        <h2 class="section-title">제안된 여행</h2>
        <div class="trip-grid" id="suggested-trips"></div>
        <div class="empty" id="suggested-empty" hidden>새로 제안할 여행이 없습니다.</div>
    </main>

    <script>
        (function () {
            var mainEl = document.querySelector('main');
            var tripsUrl = mainEl.dataset.tripsUrl;
            var savedEl = document.getElementById('saved-trips');
            var savedEmptyEl = document.getElementById('saved-empty');
            var suggestedEl = document.getElementById('suggested-trips');
            var suggestedEmptyEl = document.getElementById('suggested-empty');

            fetch(tripsUrl + '/data', { headers: { Accept: 'application/json' } })
                .then(function (res) { return res.json(); })
                .then(render)
                .catch(function () {
                    savedEmptyEl.textContent = '여행 목록을 불러오지 못했습니다.';
                    savedEmptyEl.hidden = false;
                });

            fetch(tripsUrl + '/stats', { headers: { Accept: 'application/json' } })
                .then(function (res) { return res.json(); })
                .then(renderYearlyStats)
                .catch(function () { /* 통계 로딩 실패는 조용히 무시 — 여행 목록은 별개로 동작 */ });

            function renderYearlyStats(stats) {
                var sectionEl = document.getElementById('yearly-stats');
                var titleEl = document.getElementById('yearly-stats-title');
                var summaryEl = document.getElementById('yearly-summary');
                var topSpotCardEl = document.getElementById('top-spot-card');
                var topSpotThumbEl = document.getElementById('top-spot-thumb');
                var topSpotLabelEl = document.getElementById('top-spot-label');
                var gridEl = document.getElementById('heatmap-grid');

                sectionEl.hidden = false;
                titleEl.textContent = stats.year + '년 여행 기록';
                summaryEl.textContent = '총 여행일수 ' + stats.travel_days + '일';

                if (stats.top_spot) {
                    topSpotCardEl.hidden = false;
                    if (stats.top_spot.thumbnail_url) {
                        topSpotThumbEl.src = stats.top_spot.thumbnail_url;
                        topSpotThumbEl.hidden = false;
                    } else {
                        topSpotThumbEl.hidden = true;
                    }
                    topSpotLabelEl.textContent = '가장 많이 방문한 곳 · ' + stats.top_spot.visit_count + '번 방문';
                } else {
                    topSpotCardEl.hidden = true;
                }

                renderHeatmap(gridEl, stats.year, stats.heatmap_dates);
            }

            function renderHeatmap(gridEl, year, activeDates) {
                gridEl.innerHTML = '';
                var activeSet = {};
                activeDates.forEach(function (d) { activeSet[d] = true; });

                var start = new Date(year, 0, 1);
                var end = new Date(year, 11, 31);

                // 1월 1일이 속한 주의 일요일부터 그리드를 시작한다(GitHub 스타일 정렬).
                var gridStart = new Date(start);
                gridStart.setDate(gridStart.getDate() - gridStart.getDay());

                var cursor = new Date(gridStart);
                while (cursor <= end) {
                    var cell = document.createElement('div');
                    cell.className = 'heatmap-cell';

                    if (cursor >= start && cursor <= end) {
                        var dateStr = formatDate(cursor);
                        if (activeSet[dateStr]) {
                            cell.classList.add('active');
                            cell.title = dateStr;
                        }
                    }

                    gridEl.appendChild(cell);
                    cursor.setDate(cursor.getDate() + 1);
                }
            }

            function formatDate(d) {
                var mm = String(d.getMonth() + 1).padStart(2, '0');
                var dd = String(d.getDate()).padStart(2, '0');
                return d.getFullYear() + '-' + mm + '-' + dd;
            }

            function render(data) {
                renderSaved(data.trips || []);
                renderSuggestions(data.suggestions || []);
            }

            function renderSaved(trips) {
                savedEl.innerHTML = '';
                savedEmptyEl.hidden = trips.length > 0;

                trips.forEach(function (trip) {
                    var card = document.createElement('a');
                    card.className = 'trip-card';
                    card.href = tripsUrl + '/' + trip.id;

                    if (trip.cover_thumbnail_url) {
                        var img = document.createElement('img');
                        img.className = 'trip-card-cover';
                        img.src = trip.cover_thumbnail_url;
                        img.alt = '';
                        card.appendChild(img);
                    }

                    var body = document.createElement('div');
                    body.className = 'trip-card-body';

                    var title = document.createElement('div');
                    title.className = 'trip-card-title';
                    title.textContent = trip.title;
                    body.appendChild(title);

                    var meta = document.createElement('div');
                    meta.className = 'trip-card-meta';
                    meta.textContent = formatRange(trip.start_date, trip.end_date) + ' · 사진 ' + trip.photo_count + '장';
                    body.appendChild(meta);

                    card.appendChild(body);
                    savedEl.appendChild(card);
                });
            }

            function renderSuggestions(suggestions) {
                suggestedEl.innerHTML = '';
                suggestedEmptyEl.hidden = suggestions.length > 0;

                suggestions.forEach(function (s) {
                    var card = document.createElement('div');
                    card.className = 'trip-card suggestion-card';

                    if (s.first_thumbnail_url) {
                        var img = document.createElement('img');
                        img.className = 'trip-card-cover';
                        img.src = s.first_thumbnail_url;
                        img.alt = '';
                        card.appendChild(img);
                    }

                    var body = document.createElement('div');
                    body.className = 'trip-card-body';

                    var title = document.createElement('div');
                    title.className = 'trip-card-title';
                    title.textContent = s.suggested_title;
                    body.appendChild(title);

                    var meta = document.createElement('div');
                    meta.className = 'trip-card-meta';
                    meta.textContent = formatRange(s.start_date, s.end_date) + ' · 사진 ' + s.photo_count + '장';
                    body.appendChild(meta);

                    var saveBtn = document.createElement('button');
                    saveBtn.type = 'button';
                    saveBtn.className = 'btn';
                    saveBtn.textContent = '저장';
                    body.appendChild(saveBtn);

                    var form = document.createElement('div');
                    form.className = 'suggestion-form';

                    var titleInput = document.createElement('input');
                    titleInput.type = 'text';
                    titleInput.maxLength = 100;
                    titleInput.value = s.suggested_title;
                    form.appendChild(titleInput);

                    var confirmBtn = document.createElement('button');
                    confirmBtn.type = 'button';
                    confirmBtn.className = 'btn';
                    confirmBtn.textContent = '확정';
                    form.appendChild(confirmBtn);

                    body.appendChild(form);
                    card.appendChild(body);
                    suggestedEl.appendChild(card);

                    saveBtn.addEventListener('click', function () {
                        form.classList.toggle('open');
                    });

                    confirmBtn.addEventListener('click', function () {
                        confirmBtn.disabled = true;
                        fetch(tripsUrl, {
                            method: 'POST',
                            headers: { Accept: 'application/json' },
                            body: new URLSearchParams({
                                title: titleInput.value.trim(),
                                body: '',
                                start_date: s.start_date,
                                end_date: s.end_date
                            })
                        })
                            .then(function (res) {
                                if (!res.ok) { throw new Error('save failed'); }
                                return res.json();
                            })
                            .then(function (created) {
                                window.location.href = tripsUrl + '/' + created.id;
                            })
                            .catch(function () {
                                alert('여행 저장에 실패했습니다.');
                                confirmBtn.disabled = false;
                            });
                    });
                });
            }

            function formatRange(start, end) {
                var s = start.split('-');
                var sLabel = Number(s[1]) + '월 ' + Number(s[2]) + '일';
                if (start === end) { return sLabel; }
                var e = end.split('-');
                var eLabel = s[1] === e[1] ? Number(e[2]) + '일' : Number(e[1]) + '월 ' + Number(e[2]) + '일';
                return sLabel + '~' + eLabel;
            }
        })();
    </script>
</body>
</html>
