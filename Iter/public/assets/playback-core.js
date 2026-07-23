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
