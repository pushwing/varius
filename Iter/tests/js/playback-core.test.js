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
