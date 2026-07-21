<?php

declare(strict_types=1);

/**
 * 날짜별 동선 지도(Leaflet).
 *
 * @var string $routesUrl 동선 JSON API URL(GET /routes)
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
    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
        crossorigin="">
    <style>
        html, body { margin: 0; height: 100%; font-family: system-ui, sans-serif; }
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
    </style>
</head>
<body>
    <div id="map" data-routes-url="<?= esc($routesUrl, 'attr') ?>"></div>
    <div id="legend" hidden><h4>날짜별 동선</h4><div id="legend-body"></div></div>
    <div id="empty">표시할 동선이 없습니다. 사진을 선택해 좌표를 적재하세요.</div>

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

                    // 경로선 — 같은 날짜의 이동 순서.
                    if (latlngs.length > 1) {
                        L.polyline(latlngs, { color: group.color, weight: 3, opacity: 0.8 }).addTo(map);
                    }

                    // 마커 — 같은 장소(GPS 오차 감안 약 30m 이내) 사진은 클러스터 하나로 묶인다.
                    (group.clusters || []).forEach(function (c) {
                        var popupHtml = '<div style="font-size:12px;color:#333;margin-bottom:6px;">' +
                            group.date + ' · ' + c.photos.length + '장</div>';

                        var thumbs = c.photos.filter(function (p) { return p.thumbnail_url; });
                        if (thumbs.length) {
                            popupHtml += '<div style="display:flex;gap:6px;overflow-x:auto;max-width:240px;padding-bottom:2px;">';
                            thumbs.forEach(function (p) {
                                popupHtml += '<img src="' + p.thumbnail_url + '" alt="" title="' + p.taken_at + '" ' +
                                    'style="height:120px;border-radius:6px;flex:none;">';
                            });
                            popupHtml += '</div>';
                        } else {
                            popupHtml += c.photos[0].taken_at;
                        }

                        L.circleMarker([c.lat, c.lng], {
                            radius: 6, color: group.color, fillColor: group.color, fillOpacity: 0.9
                        }).addTo(map).bindPopup(popupHtml, { maxWidth: 260 });
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

            function showEmpty() {
                document.getElementById('empty').style.display = 'flex';
            }
        })();
    </script>
</body>
</html>
