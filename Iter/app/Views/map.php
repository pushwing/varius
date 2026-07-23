<?php

declare(strict_types=1);

/**
 * 날짜별 동선 지도(Leaflet).
 *
 * @var string $routesUrl   동선 JSON API URL(GET /routes)
 * @var string $timelineUrl 시간별 동선 API URL 프리픽스(GET /timeline/{date} 등)
 * @var string $photosUrl   사진 관리 API URL 프리픽스(POST /photos/{id}/rotate 등)
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
            font-size: 13px; display: flex; flex-direction: column;
        }
        #route-sidebar-header {
            padding: 14px 16px; font-weight: 600; font-size: 14px; border-bottom: 1px solid #eee;
        }
        #route-sidebar-body { flex: 1; overflow-y: auto; }
        #route-sidebar-footer {
            padding: 10px 16px; font-size: 12px; color: #777;
            border-top: 1px solid #eee; background: #fafafa;
        }
        .month-group { border-bottom: 1px solid #eee; }
        .month-header {
            display: block; width: 100%; text-align: left; padding: 10px 16px;
            border: none; background: #f7f7f7; cursor: pointer; font-size: 13px; font-weight: 600;
        }
        .month-header:hover { background: #eee; }
        .day-list[hidden] { display: none; }
        .day-row { display: flex; align-items: stretch; }
        .day-row:hover { background: #f0f4ff; }
        .day-item {
            display: flex; align-items: center; gap: 6px; flex: 1; min-width: 0; text-align: left;
            padding: 8px 4px 8px 24px; border: none; background: none; cursor: pointer;
            font-size: 13px; color: #333;
        }
        .day-item.active { background: #e3edff; font-weight: 600; }
        .day-timeline-btn {
            flex: none; border: none; background: none; cursor: pointer;
            padding: 0 14px 0 6px; font-size: 11px; color: #1a73e8;
        }
        .day-timeline-btn:hover { text-decoration: underline; }
        .day-swatch { width: 10px; height: 10px; border-radius: 2px; flex: none; }
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

        /* ── 시간별 동선 레이어(여행 스케줄 뷰) ── */
        #timeline-layer {
            position: fixed; inset: 0; z-index: 2000; background: rgba(0, 0, 0, 0.75);
            display: flex; align-items: center; justify-content: center; padding: 20px;
        }
        #timeline-layer[hidden] { display: none; }
        #timeline-panel {
            background: #fff; border-radius: 10px; max-width: 760px; width: 100%;
            max-height: 88vh; display: flex; flex-direction: column; overflow: hidden;
        }
        #timeline-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 18px; border-bottom: 1px solid #eee;
        }
        #timeline-header h3 { margin: 0; font-size: 15px; padding-right: 12px; }
        #timeline-header-actions { display: flex; align-items: center; gap: 10px; flex: none; }
        #timeline-close {
            border: none; background: none; font-size: 20px; cursor: pointer; color: #666; line-height: 1;
        }
        #timeline-share { position: relative; }
        #timeline-share-toggle {
            border: 1px solid #dbe5ff; border-radius: 8px; background: #f2f6ff; color: #1a73e8;
            font-size: 12px; padding: 6px 12px; cursor: pointer;
        }
        #timeline-share-toggle:hover { background: #e6edff; }
        #timeline-share-menu {
            position: absolute; top: calc(100% + 6px); right: 0; z-index: 10;
            background: #fff; border: 1px solid #e2e2e2; border-radius: 10px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.12); padding: 6px; min-width: 140px;
        }
        .share-option {
            display: block; width: 100%; text-align: left; border: none; background: none;
            padding: 8px 10px; font-size: 13px; color: #333; cursor: pointer; border-radius: 6px;
        }
        .share-option:hover { background: #f4f6fb; }
        #timeline-body { overflow-y: auto; padding: 16px 22px 22px; }
        #timeline-daynote {
            background: #f7f9ff; border: 1px solid #dbe5ff; border-radius: 10px;
            padding: 12px 14px 10px; margin-bottom: 18px;
        }
        #timeline-daynote input, #timeline-daynote textarea {
            width: 100%; box-sizing: border-box; border: 1px solid #ccd6f0; border-radius: 6px;
            font: inherit; padding: 6px 9px; background: #fff;
        }
        #timeline-daynote input { font-weight: 600; font-size: 15px; margin-bottom: 6px; }
        #timeline-daynote textarea { resize: vertical; min-height: 44px; font-size: 13px; }
        #timeline-daynote-actions { text-align: right; margin-top: 6px; }
        .timeline-hour { display: flex; }
        .timeline-hour-time {
            flex: none; width: 52px; text-align: right; padding-top: 1px;
            font-weight: 600; font-size: 13px; color: #1a73e8;
        }
        .timeline-hour-content {
            flex: 1; min-width: 0; margin-left: 14px; padding: 0 0 18px 16px;
            border-left: 2px solid #dbe5ff; position: relative;
        }
        .timeline-hour:last-child .timeline-hour-content { border-left-color: transparent; }
        .timeline-hour-content::before {
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
        #timeline-empty { color: #777; font-size: 13px; padding: 8px 0; }

        /* ── 사진 확대 뷰어(보관된 이미지를 크게 표시) ── */
        #photo-layer-grid img, .timeline-photos img { cursor: zoom-in; }
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
    </style>
</head>
<body>
    <?= view('partials/nav', ['uploadUrl' => $uploadUrl, 'mapUrl' => $mapUrl, 'tripsUrl' => $tripsUrl, 'logoutUrl' => $logoutUrl]) ?>
    <div id="map-container">
        <div id="route-sidebar">
            <div id="route-sidebar-header">동선 목록</div>
            <div id="route-sidebar-body"></div>
            <div id="route-sidebar-footer">날짜를 클릭하면 그 날의 첫 번째 장소로 이동합니다.</div>
        </div>
        <div id="map" data-routes-url="<?= esc($routesUrl, 'attr') ?>" data-timeline-url="<?= esc($timelineUrl, 'attr') ?>" data-photos-url="<?= esc($photosUrl, 'attr') ?>"></div>
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

    <div id="timeline-layer" hidden>
        <div id="timeline-panel">
            <div id="timeline-header">
                <h3 id="timeline-title"></h3>
                <div id="timeline-header-actions">
                    <div id="timeline-share">
                        <button type="button" id="timeline-share-toggle">🔗 공유</button>
                        <div id="timeline-share-menu" hidden>
                            <button type="button" class="share-option" data-share="x">X(트위터)</button>
                            <button type="button" class="share-option" data-share="facebook">페이스북</button>
                            <button type="button" class="share-option" data-share="kakao">카카오톡</button>
                            <button type="button" class="share-option" data-share="instagram">인스타그램</button>
                            <button type="button" class="share-option" data-share="copy">링크 복사</button>
                        </div>
                    </div>
                    <button type="button" id="timeline-close" aria-label="닫기">&times;</button>
                </div>
            </div>
            <div id="timeline-body">
                <div id="timeline-daynote">
                    <input type="text" id="timeline-daynote-title" maxlength="100"
                           placeholder="이 날의 제목 (예: 서울 여행 1일차)">
                    <textarea id="timeline-daynote-body" maxlength="2000"
                              placeholder="이 날의 일정·메모를 남겨보세요"></textarea>
                    <div id="timeline-daynote-actions">
                        <button type="button" class="note-save-btn" id="timeline-daynote-save">저장</button>
                    </div>
                </div>
                <div id="timeline-hours"></div>
            </div>
        </div>
    </div>

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
            bindBackdropClose(layerEl, closeLayer);

            // 배경 클릭으로 닫되, "클릭이 배경에서 시작된 경우"만 닫는다.
            // 메모 등 내용 안에서 텍스트를 드래그 선택하다 배경 위에서 마우스를 놓으면
            // click 이 배경에서 발생해 레이어가 닫혀버리는 문제 방지.
            function bindBackdropClose(overlayEl, closeFn) {
                var downOnBackdrop = false;
                overlayEl.addEventListener('mousedown', function (evt) {
                    downOnBackdrop = evt.target === overlayEl;
                });
                overlayEl.addEventListener('click', function (evt) {
                    if (downOnBackdrop && evt.target === overlayEl) { closeFn(); }
                    downOnBackdrop = false;
                });
            }

            var timelineEl = document.getElementById('timeline-layer');
            var timelineTitleEl = document.getElementById('timeline-title');
            var timelineHoursEl = document.getElementById('timeline-hours');
            var dayNoteTitleEl = document.getElementById('timeline-daynote-title');
            var dayNoteBodyEl = document.getElementById('timeline-daynote-body');
            var currentTimelineDate = null;

            document.getElementById('timeline-close').addEventListener('click', closeTimeline);
            bindBackdropClose(timelineEl, closeTimeline);
            document.getElementById('timeline-daynote-save').addEventListener('click', saveDayNote);

            // 사진 확대 뷰어 — 시간표·사진 레이어의 썸네일 클릭 시 보관된 이미지를 크게 표시.
            var photosUrl = mapEl.dataset.photosUrl;
            var viewerEl = document.getElementById('photo-viewer');
            var viewerImgEl = document.getElementById('photo-viewer-img');
            var viewerCaptionEl = document.getElementById('photo-viewer-caption');
            var viewerPrevEl = document.getElementById('photo-viewer-prev');
            var viewerNextEl = document.getElementById('photo-viewer-next');
            var viewerPhotoId = null;
            var viewerList = [];   // 현재 레이어에서 넘겨볼 수 있는 썸네일 목록
            var viewerIndex = -1;

            // 클릭한 썸네일이 속한 레이어의 사진 목록을 수집해 그 위치부터 넘겨본다.
            function openViewerFrom(imgEl) {
                var container = imgEl.closest('#photo-layer-grid') ? '#photo-layer-grid img' : '.timeline-photos img';
                viewerList = Array.prototype.slice.call(document.querySelectorAll(container));
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

            // 컨트롤·이동 버튼 클릭은 닫힘으로 처리하지 않는다.
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
                        // 레이어 목록의 같은 썸네일도 갱신한다.
                        document.querySelectorAll('.timeline-photos img, #photo-layer-grid img').forEach(function (img) {
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

            // 삭제 — 썸네일 파일과 위치 기록(DB)이 함께 삭제된다.
            document.getElementById('photo-viewer-delete').addEventListener('click', function () {
                if (!viewerPhotoId) { return; }
                if (!window.confirm('이 사진을 삭제할까요? 썸네일과 위치 기록이 함께 삭제됩니다.')) { return; }

                var buttonEl = this;
                buttonEl.disabled = true;
                fetch(photosUrl + '/' + viewerPhotoId + '/delete', {
                    method: 'POST',
                    headers: { Accept: 'application/json' }
                })
                    .then(function (res) {
                        if (!res.ok) { throw new Error('delete failed'); }
                        // 삭제 후에는 뷰어와 열린 레이어를 모두 닫는다(지도 마커는 새로고침 시 반영).
                        closeViewer();
                        closeLayer();
                        closeTimeline();
                    })
                    .catch(function () { alert('삭제에 실패했습니다.'); })
                    .then(function () { buttonEl.disabled = false; });
            });

            // 팝업/사이드바 모두 매번 새로 DOM 에 그려지므로 이벤트 위임으로 클릭을 잡는다.
            document.body.addEventListener('click', function (evt) {
                var photoImg = evt.target.closest('.timeline-photos img, #photo-layer-grid img');
                if (photoImg) { openViewerFrom(photoImg); return; }

                var btn = evt.target.closest('.popup-more-btn');
                if (btn) { openLayer(Number(btn.dataset.clusterIndex)); return; }

                var monthHeader = evt.target.closest('.month-header');
                if (monthHeader) { toggleMonth(monthHeader); return; }

                var timelineBtn = evt.target.closest('.day-timeline-btn');
                if (timelineBtn) {
                    // 시간표를 열면서 지도도 그 날짜의 동선 범위로 이동한다.
                    var timelineDate = timelineBtn.dataset.date;
                    var dateEntry = dateIndex[timelineDate];
                    if (dateEntry && dateEntry.latlngs.length) {
                        map.fitBounds(dateEntry.latlngs, { padding: [40, 40] });
                    }
                    document.querySelectorAll('.day-item.active').forEach(function (el) { el.classList.remove('active'); });
                    var dayItemEl = document.querySelector('.day-item[data-date="' + timelineDate + '"]');
                    if (dayItemEl) { dayItemEl.classList.add('active'); }

                    openTimeline(timelineDate);
                    return;
                }

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
                if (entry.firstClusterIndex !== null) {
                    var cluster = clusterRegistry[entry.firstClusterIndex];
                    // 레이어를 닫아도 어느 지점인지 알 수 있도록, 실제로 마커를 클릭한 것처럼 팝업도 함께 연다.
                    if (cluster && cluster.marker) { cluster.marker.openPopup(); }
                    openLayer(entry.firstClusterIndex);
                }

                document.querySelectorAll('.day-item.active').forEach(function (el) { el.classList.remove('active'); });
                itemEl.classList.add('active');
            }

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
                        var registryEntry = { date: group.date, photos: c.photos, marker: null };
                        clusterRegistry.push(registryEntry);
                        if (firstClusterIndex === null) { firstClusterIndex = clusterIndex; }

                        // 클러스터의 첫 사진 촬영 시각(HH:MM) — 같은 장소에서 찍힌 사진들의 대표 시각으로 보여준다.
                        var firstPhotoTime = c.photos.length ? c.photos[0].taken_at.slice(11, 16) : '';

                        var popupHtml = '<div style="font-size:12px;color:#333;">' +
                            group.date + (firstPhotoTime ? ' ' + firstPhotoTime : '') + ' · ' + c.photos.length + '장</div>' +
                            '<button type="button" class="popup-more-btn" data-cluster-index="' + clusterIndex + '">더보기</button>';

                        registryEntry.marker = L.circleMarker([c.lat, c.lng], {
                            radius: 6, color: group.color, fillColor: group.color, fillOpacity: 0.9
                        }).addTo(map).bindPopup(popupHtml, { maxWidth: 180 });

                        // 지점 액션은 클릭이 아니라 마우스오버로 연다(#27).
                        // 클릭(bindPopup 기본 동작)도 유지한다 — 터치 기기엔 hover 가 없다.
                        registryEntry.marker.on('mouseover', function () { this.openPopup(); });
                    });

                    dateIndex[group.date] = { latlngs: latlngs, firstClusterIndex: firstClusterIndex };
                    dateOrder.push({ date: group.date, count: group.points.length, color: group.color });
                });

                renderSidebar(dateOrder);
                if (bounds.length) { map.fitBounds(bounds, { padding: [40, 40] }); }
                focusDateFromQuery();
            }

            // 업로드 완료 화면의 "지도에서 보기"(?date=YYYY-MM-DD)로 들어오면, 사이드바에서
            // 그 날짜를 선택하고 지도를 그 날의 첫 지점으로 이동한 상태로 연다.
            function focusDateFromQuery() {
                var date = new URLSearchParams(location.search).get('date');
                if (!date || !/^\d{4}-\d{2}-\d{2}$/.test(date) || !dateIndex[date]) { return; }

                var itemEl = document.querySelector('.day-item[data-date="' + date + '"]');
                if (!itemEl) { return; }

                var listEl = itemEl.closest('.day-list');
                if (listEl) { listEl.hidden = false; } // 접혀 있는 달이면 펼친다.

                selectDay(itemEl);
                itemEl.scrollIntoView({ block: 'nearest' });
            }

            function renderSidebar(dateOrder) {
                var monthMap = {}; // 'YYYY-MM' → list<{date, count, color}>
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

                        var rowEl = document.createElement('div');
                        rowEl.className = 'day-row';

                        var itemEl = document.createElement('button');
                        itemEl.type = 'button';
                        itemEl.className = 'day-item';
                        itemEl.dataset.date = entry.date;

                        var swatchEl = document.createElement('span');
                        swatchEl.className = 'day-swatch';
                        swatchEl.style.background = entry.color;
                        itemEl.appendChild(swatchEl);

                        var labelEl = document.createElement('span');
                        labelEl.textContent = monthNum + '월 ' + dayNum + '일 (' + entry.count + '장)';
                        itemEl.appendChild(labelEl);

                        // 날짜 옆 시간별 동선(여행 스케줄) 진입 링크.
                        var timelineBtnEl = document.createElement('button');
                        timelineBtnEl.type = 'button';
                        timelineBtnEl.className = 'day-timeline-btn';
                        timelineBtnEl.dataset.date = entry.date;
                        timelineBtnEl.textContent = '시간표';
                        timelineBtnEl.title = '시간별 동선 보기';

                        rowEl.appendChild(itemEl);
                        rowEl.appendChild(timelineBtnEl);
                        listEl.appendChild(rowEl);
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

            // ── 시간별 동선 레이어(여행 스케줄 뷰) ──

            var timelineUrl = mapEl.dataset.timelineUrl;

            function openTimeline(date) {
                currentTimelineDate = date;
                fetch(timelineUrl + '/' + date, { headers: { Accept: 'application/json' } })
                    .then(function (res) {
                        if (!res.ok) { throw new Error('timeline fetch failed'); }
                        return res.json();
                    })
                    .then(renderTimeline)
                    .catch(function () {
                        timelineTitleEl.textContent = date;
                        timelineHoursEl.innerHTML = '';
                        timelineHoursEl.appendChild(emptyMessage('시간별 동선을 불러오지 못했습니다.'));
                        timelineEl.hidden = false;
                    });
            }

            function closeTimeline() {
                timelineEl.hidden = true;
                shareMenuEl.hidden = true;
            }

            // ── SNS 공유 ──

            var shareToggleEl = document.getElementById('timeline-share-toggle');
            var shareMenuEl = document.getElementById('timeline-share-menu');
            var shareUrlCache = {}; // 날짜 → 공유 URL(같은 세션 내 재요청 방지)

            shareToggleEl.addEventListener('click', function (evt) {
                evt.stopPropagation();
                shareMenuEl.hidden = !shareMenuEl.hidden;
            });
            document.addEventListener('click', function (evt) {
                if (!shareMenuEl.hidden && !evt.target.closest('#timeline-share')) { shareMenuEl.hidden = true; }
            });
            shareMenuEl.addEventListener('click', function (evt) {
                var btn = evt.target.closest('.share-option');
                if (btn) { handleShareOption(btn.dataset.share); }
            });

            function getShareUrl() {
                if (!currentTimelineDate) { return Promise.reject(new Error('날짜 없음')); }
                if (shareUrlCache[currentTimelineDate]) { return Promise.resolve(shareUrlCache[currentTimelineDate]); }

                return fetch(timelineUrl + '/share', {
                    method: 'POST',
                    headers: { Accept: 'application/json' },
                    body: new URLSearchParams({ date: currentTimelineDate })
                })
                    .then(function (res) {
                        if (!res.ok) { throw new Error('공유 링크 생성 실패'); }
                        return res.json();
                    })
                    .then(function (data) {
                        shareUrlCache[currentTimelineDate] = data.url;
                        return data.url;
                    });
            }

            function handleShareOption(kind) {
                getShareUrl().then(function (url) {
                    var title = timelineTitleEl.textContent;

                    if (kind === 'x') {
                        openShareWindow('https://twitter.com/intent/tweet?url=' + encodeURIComponent(url) + '&text=' + encodeURIComponent(title));
                    } else if (kind === 'facebook') {
                        openShareWindow('https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(url));
                    } else if (kind === 'kakao' || kind === 'instagram') {
                        // 카카오톡·인스타그램은 웹에서 바로 공유할 URL 스킴이 없다 — 모바일에서는
                        // OS 공유 시트(navigator.share, 설치된 앱 선택 가능)로, 그 외엔 링크 복사로 대신한다.
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

            function openShareWindow(url) {
                window.open(url, '_blank', 'noopener,width=560,height=480');
            }

            function copyToClipboard(text) {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text);
                    return;
                }
                // 클립보드 API 미지원 브라우저 폴백.
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); } catch (e) { /* 무시 — 최소한 alert 로 URL 은 안내된다 */ }
                document.body.removeChild(ta);
            }

            function renderTimeline(data) {
                var d = data.date;
                timelineTitleEl.textContent = Number(d.slice(0, 4)) + '년 ' +
                    Number(d.slice(5, 7)) + '월 ' + Number(d.slice(8, 10)) + '일 시간별 동선';

                dayNoteTitleEl.value = data.day_note ? data.day_note.title : '';
                dayNoteBodyEl.value = data.day_note ? data.day_note.body : '';

                timelineHoursEl.innerHTML = '';
                if (!data.slots.length) {
                    timelineHoursEl.appendChild(emptyMessage('이 날짜에 표시할 사진이 없습니다. 아래에서 메모만 남길 수도 있어요.'));
                }

                var poiTasks = []; // {el, lat, lng} — 사진이 먼저 뜨도록 POI 조회는 뒤로 미룬다.
                var pendingImgs = [];
                data.slots.forEach(function (slotEntry) {
                    timelineHoursEl.appendChild(buildSlotRow(slotEntry, poiTasks, pendingImgs));
                });

                timelineEl.hidden = false;

                // 썸네일이 모두 뜬 뒤(최대 4초 대기) 주변 업장 정보를 한 건씩 차례로 불러온다.
                // POI 를 먼저·병렬로 부르면 세션 잠금 탓에 썸네일 응답이 그 뒤로 밀린다.
                var renderedDate = currentTimelineDate;
                whenImagesSettled(pendingImgs, 4000).then(function () {
                    poiTasks.reduce(function (chain, task) {
                        return chain.then(function () {
                            // 레이어를 닫았거나 다른 날짜로 넘어갔으면 중단.
                            if (timelineEl.hidden || currentTimelineDate !== renderedDate) { return; }
                            return loadPoi(task.el, task.lat, task.lng);
                        });
                    }, Promise.resolve());
                });
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

            function emptyMessage(text) {
                var el = document.createElement('div');
                el.id = 'timeline-empty';
                el.textContent = text;
                return el;
            }

            function buildSlotRow(slotEntry, poiTasks, pendingImgs) {
                var rowEl = document.createElement('div');
                rowEl.className = 'timeline-hour';

                var timeEl = document.createElement('div');
                timeEl.className = 'timeline-hour-time';
                timeEl.textContent = slotEntry.label;
                rowEl.appendChild(timeEl);

                var contentEl = document.createElement('div');
                contentEl.className = 'timeline-hour-content';

                if (slotEntry.photos.length) {
                    var countEl = document.createElement('div');
                    countEl.className = 'timeline-count';
                    countEl.textContent = '사진 ' + slotEntry.photos.length + '장';
                    contentEl.appendChild(countEl);
                }

                // 주변 업장 정보(식당·카페 등) 자리 — 실제 조회는 사진 로드 후 순차 실행된다.
                if (slotEntry.lat !== null && slotEntry.lng !== null) {
                    var poiEl = document.createElement('div');
                    poiEl.className = 'timeline-poi';
                    contentEl.appendChild(poiEl);
                    poiTasks.push({ el: poiEl, lat: slotEntry.lat, lng: slotEntry.lng });
                } else if (slotEntry.photos.length) {
                    var noLocationEl = document.createElement('div');
                    noLocationEl.className = 'timeline-poi';
                    noLocationEl.textContent = '위치 정보 없음';
                    contentEl.appendChild(noLocationEl);
                }

                if (slotEntry.photos.length) {
                    var photosEl = document.createElement('div');
                    photosEl.className = 'timeline-photos';
                    slotEntry.photos.forEach(function (p) {
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

                contentEl.appendChild(buildMemoInput(slotEntry));
                rowEl.appendChild(contentEl);
                return rowEl;
            }

            function buildMemoInput(slotEntry) {
                var wrapEl = document.createElement('div');
                wrapEl.className = 'timeline-memo';

                var inputEl = document.createElement('input');
                inputEl.type = 'text';
                inputEl.maxLength = 500;
                inputEl.placeholder = '이 시간에 한 일을 메모해보세요';
                inputEl.value = slotEntry.memo || '';
                wrapEl.appendChild(inputEl);

                var saveEl = document.createElement('button');
                saveEl.type = 'button';
                saveEl.className = 'note-save-btn';
                saveEl.textContent = '저장';
                saveEl.addEventListener('click', function () {
                    postNote('time-note', {
                        date: currentTimelineDate,
                        slot: slotEntry.slot,
                        memo: inputEl.value.trim()
                    }, saveEl);
                });
                wrapEl.appendChild(saveEl);

                return wrapEl;
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

            function saveDayNote() {
                postNote('day-note', {
                    date: currentTimelineDate,
                    title: dayNoteTitleEl.value.trim(),
                    body: dayNoteBodyEl.value.trim()
                }, document.getElementById('timeline-daynote-save'));
            }

            // 노트 저장 공통 처리 — 버튼에 저장 중/완료/실패 피드백을 준다.
            function postNote(kind, fields, buttonEl) {
                if (!currentTimelineDate) { return; }
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

            function showEmpty() {
                document.getElementById('empty').style.display = 'flex';
            }
        })();
    </script>
</body>
</html>
