<?php

declare(strict_types=1);

/**
 * 날짜별 동선 지도(Leaflet).
 *
 * @var string $routesUrl 동선 JSON API URL(GET /routes)
 * @var string $mapUrl
 * @var string $logoutUrl
 */
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iter — 동선 지도</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png">
    <link rel="stylesheet" href="/assets/nav.css">
    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
        crossorigin="">
    <style>
        html, body { margin: 0; height: 100%; font-family: system-ui, sans-serif; }
        body { display: flex; flex-direction: column; }
        #map-container { position: relative; flex: 1; min-height: 0; }
        #map { position: absolute; top: 0; right: 0; bottom: 0; left: 280px; }
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
    <?= view('partials/nav', ['mapUrl' => $mapUrl, 'logoutUrl' => $logoutUrl]) ?>
    <div id="map-container">
        <div id="route-sidebar">
            <div id="route-sidebar-header">동선 목록</div>
            <div id="route-sidebar-body"></div>
        </div>
        <div id="map" data-routes-url="<?= esc($routesUrl, 'attr') ?>"></div>
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
        (function () {
            var mapEl = document.getElementById('map');
            var map = L.map('map').setView([37.5665, 126.9780], 11);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(map);

            var clusterRegistry = []; // 인덱스 → { date, photos } — 팝업의 "더보기" 클릭 시 조회용.
            var dateIndex = {}; // 날짜(YYYY-MM-DD) → { latlngs, firstClusterIndex } — 사이드바 일자 클릭 시 조회용.

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
                var dateOrder = []; // 화면에 보여줄 날짜 순서(원본 오름차순 유지) — 사이드바 정렬용.

                dates.forEach(function (group) {
                    var latlngs = group.points.map(function (p) { return [p.lat, p.lng]; });
                    bounds = bounds.concat(latlngs);

                    // 경로선 — 같은 날짜의 이동 순서.
                    if (latlngs.length > 1) {
                        L.polyline(latlngs, { color: group.color, weight: 3, opacity: 0.8 }).addTo(map);
                    }

                    var firstClusterIndex = null;

                    // 마커 — 같은 장소(GPS 오차 감안 약 30m 이내) 사진은 클러스터 하나로 묶인다.
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
            }

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
