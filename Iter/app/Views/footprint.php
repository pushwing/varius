<?php

declare(strict_types=1);

/**
 * 발자국 지도 — 방문 국가·국내 시·도를 색칠한 choropleth.
 *
 * @var string $dataUrl   집계 JSON API URL(GET /footprint/data)
 * @var string $uploadUrl
 * @var string $mapUrl
 * @var string $tripsUrl
 * @var string $logoutUrl
 */
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iter — 발자국 지도</title>
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
        #stats-bar {
            display: flex; gap: 12px; padding: 12px 16px; background: #fff;
            border-bottom: 1px solid #eee; font-size: 14px; align-items: center;
        }
        .stat-card {
            background: #f0f4ff; border-radius: 8px; padding: 8px 14px; color: #1a3e8e;
        }
        .stat-card strong { font-size: 16px; color: #1a73e8; }
        #footprint-map { flex: 1; min-height: 0; }
        .region-tooltip { font-size: 12px; }
    </style>
</head>
<body>
    <?= view('partials/nav', ['uploadUrl' => $uploadUrl, 'mapUrl' => $mapUrl, 'tripsUrl' => $tripsUrl, 'logoutUrl' => $logoutUrl]) ?>
    <div id="stats-bar">
        <span class="stat-card">방문 국가 <strong id="stat-countries">-</strong>개국</span>
        <span class="stat-card">국내 시·도 <strong id="stat-regions">-</strong>/17</span>
    </div>
    <div id="footprint-map" data-url="<?= esc($dataUrl, 'attr') ?>"></div>

    <script
        src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""></script>
    <script>
        (function () {
            var mapEl = document.getElementById('footprint-map');
            var map = L.map('footprint-map', { minZoom: 2, maxZoom: 10, worldCopyJump: true })
                .setView([30, 60], 2);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(map);

            var VISITED_STYLE = { color: '#1a73e8', weight: 1, fillColor: '#1a73e8', fillOpacity: 0.5 };
            var EMPTY_STYLE = { color: '#bbb', weight: 1, fillColor: '#ccc', fillOpacity: 0.15 };

            // 집계 + 경계 두 파일을 함께 받아 그린다.
            Promise.all([
                fetch(mapEl.dataset.url, { headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }),
                fetch('/assets/geo/world-countries.json').then(function (r) { return r.json(); }),
                fetch('/assets/geo/kr-sido.json').then(function (r) { return r.json(); })
            ]).then(function (results) {
                var data = results[0];
                var world = results[1];
                var sido = results[2];

                document.getElementById('stat-countries').textContent = data.stats.countryCount;
                document.getElementById('stat-regions').textContent = data.stats.regionCount;

                var photosByCode = {}; // iso 코드 → 사진 수 (국가·시·도 공용)
                (data.countries || []).forEach(function (c) { photosByCode[c.code] = c.photos; });
                (data.regions || []).forEach(function (r) { photosByCode[r.code] = r.photos; });

                function styleFor(feature) {
                    return photosByCode[feature.properties.iso] ? VISITED_STYLE : EMPTY_STYLE;
                }

                function bindTooltip(feature, layer) {
                    var n = photosByCode[feature.properties.iso];
                    var label = feature.properties.name + (n ? ' · 사진 ' + n + '장' : ' · 미방문');
                    layer.bindTooltip(label, { sticky: true, className: 'region-tooltip' });
                }

                // 세계 — 한국은 시·도 레이어로 대체하므로 국가 폴리곤에서 제외한다.
                L.geoJSON(world, {
                    filter: function (feature) { return feature.properties.iso !== 'KR'; },
                    style: styleFor,
                    onEachFeature: bindTooltip
                }).addTo(map);

                L.geoJSON(sido, { style: styleFor, onEachFeature: bindTooltip }).addTo(map);
            }).catch(function () {
                document.getElementById('stats-bar').textContent = '발자국 데이터를 불러오지 못했습니다.';
            });
        })();
    </script>
</body>
</html>
