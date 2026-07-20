# 실시간 인터콤 (외부 공중인터넷 → 리눅스 스피커) 아키텍처 스펙

## 1. 목표
외부 공중 인터넷에서 접속한 클라이언트(웹/앱)의 음성을 리눅스 서버에 연결된 물리 스피커로
실시간(지연 200~300ms 이내)으로 전달한다. WebRTC 기반, NAT 통과를 위한 TURN 필수.

## 2. 전체 구성 요소

| 구성 요소 | 역할 | 기술 |
|---|---|---|
| 클라이언트(외부용 앱) | 로그인, 마이크 캡처, 목소리 전송, 리턴 메시지 표시 | 웹(getUserMedia) / 앱 |
| CI4 API | 인증, 룸 토큰(JWT) 발급, 디바이스/이력 관리 | PHP 8.2+, CodeIgniter 4 |
| SFU / 시그널링 | WebRTC 시그널링, 미디어 라우팅, 데이터 채널(리턴 메시지) | LiveKit (셀프호스팅 1순위), 대안: Janus |
| TURN 서버 | NAT 통과, 미디어 릴레이 | LiveKit 내장 TURN 사용(이슈 #6). 별도 coturn(#5)은 인프라만 준비된 상태로 보류 — 3·4절 참고 |
| 리눅스 수신 데몬 | SFU에 참가자로 접속, 오디오 디코딩 후 스피커 출력, 상태를 데이터 채널로 회신(예정) | 우분투 서버(자택 설치), Python + GStreamer(`livekitwebrtcsrc`) → ALSA/PulseAudio, systemd 상주 |

## 3. 처리 흐름
1. 클라이언트가 CI4 API에 인증 요청 → 짧은 만료시간의 **LiveKit 액세스 토큰**(HS256 JWT, VideoGrant 포함) 발급. CI4가 자체 포맷 JWT를 발급하던 초기 설계(이슈 #4)를 LiveKit이 직접 검증 가능한 토큰 포맷으로 대체했다(이슈 #6).
2. 클라이언트가 발급받은 토큰으로 LiveKit(SFU)에 접속, WebRTC 오퍼/앤서 교환
3. NAT 통과 실패 시 LiveKit 내장 TURN을 통해 미디어 릴레이(4절 참고)
4. 리눅스 수신 데몬은 서버가 지정한 특정 room에만 참가자로 join (임의 room 지정 불가)
5. 데몬이 수신한 Opus 오디오를 GStreamer 파이프라인으로 디코딩 → ALSA로 실시간 출력

## 4. 보안 요구사항
- WebRTC 미디어는 DTLS-SRTP로 기본 암호화됨
- CI4가 발급하는 LiveKit 액세스 토큰은 짧은 만료시간(TTL, 기본 300초) 적용, VideoGrant로 room·publish·subscribe·데이터 채널 권한만 최소 부여
- TURN 인증은 LiveKit 내장 TURN이 토큰 검증과 함께 통합 처리한다. 별도 coturn(#5)의 `use-auth-secret` HMAC credential은 LiveKit의 외부 TURN 연동이 고정 정적 credential만 지원해 현재 흐름과 맞지 않아 연동을 보류했다 — coturn 인프라 코드는 남겨두고 필요 시 재검토한다.
- 리눅스 데몬의 room 접근은 서버 측에서 강제 (클라이언트 임의 지정 금지)

## 5. 온프레미스 배포 가이드
- coturn, LiveKit, CI4 API 모두 **자택 서버에 온프레미스로 설치**한다(클라우드 미사용).
- 공유기/방화벽 포트포워딩: TCP 80·443(Caddy, Let's Encrypt+CI4 API+LiveKit 시그널링), UDP 3478(LiveKit 내장 TURN), UDP 50000–50100(LiveKit ICE 미디어). coturn은 현재 미사용이라 별도 포워딩하지 않는다(4절). 상세·자동화 스크립트는 [`infra/`](infra/) 참고(이슈 #8).
- 외부 도메인 연결은 DDNS(고정 IP가 아닐 경우, `infra/ddns/`)로 유지하고, TLS 인증서는 Caddy가 Let's Encrypt로 자동 발급·갱신한다(`infra/caddy/Caddyfile`) — certbot 등 별도 도구 불필요.
- 리전 다중화는 해당 없음(단일 자택 서버 구성).
- LiveKit 배포 파일(`docker-compose.yml`, `livekit.yaml`)은 [`livekit/`](livekit/)에 있다. API key/secret은 `livekit/.env`의 `LIVEKIT_KEYS`로 주입하며, CI4 `.env`의 `livekit.apiKey`/`livekit.apiSecret`과 반드시 동일해야 한다. 토큰 발급은 CI4 앱의 `app/Libraries/LiveKitAccessTokenService.php`가 담당한다.
- coturn 배포 파일(`docker-compose.yml`, `turnserver.conf`)은 [`coturn/`](coturn/)에 있다(현재 미사용, 4절 참고). `use-auth-secret` credential 발급 로직은 `app/Libraries/TurnCredentialService.php`에 남아 있다.

## 6. 리눅스 수신 데몬 요구사항
- 네트워크 끊김 시 자동 재접속 (지수 백오프) — **파이프라인별 in-process 재시작**(`rtic_daemon/supervisor.py`의 `SupervisedPipeline`, 기본 1초→최대 30초 백오프)으로 처리한다. 프로세스는 죽지 않고 해당 파이프라인만 다시 세운다 → 양방향에서 한 방향 끊김이 다른 방향을 죽이지 않는다(수신 `livekitwebrtcsrc`의 nicesrc가 앱 송신 종료 시 올리는 "Internal data stream error"가 마이크 퍼블리시를 끊지 않게). systemd `Restart=on-failure`는 프로세스 크래시 안전망으로만 남는다. (초기 단방향 설계는 에러 시 프로세스를 코드 1로 종료해 systemd `RestartSteps` 백오프에 위임했으나, 양방향 확장(#11) 후 위 방식으로 대체.)
- SFU 연결 상태 헬스체크 노출 — Prometheus `/metrics`(`rtic_daemon_up`, `rtic_daemon_pipeline_state`), 자체 구축 Grafana에서 스크레이프.
- 오디오 출력/입력 실패 시 해당 파이프라인 in-process 백오프 재시작(위 참고). 프로세스 크래시 시엔 `systemd` `Restart=on-failure`가 안전망.
- 구현·배포 파일은 [`daemon/`](daemon/) 참고(Python + GStreamer/PyGObject, `livekitwebrtcsrc` 사용 — 표준 apt에 없어 소스 빌드 필요. 실서버 배포에서 검증한 필수 조건: `gstreamer1.0-nice`, 실행 사용자 `audio` 그룹, 실시간 오디오 sink `sync=false`. `daemon/README.md` 참고).
- "상태를 데이터 채널로 회신"(2절)은 `rtic_daemon/status_reporter.py`(이슈 #12)가 담당 — LiveKit 텍스트 스트림(토픽 `rtic.status`)으로 발신, 오디오 참가자와는 별개의 데이터 채널 전용 참가자(`<identity>-status`) 사용.

## 7. 개발 순서 제안 (Claude Code 작업 단위)
1. [x] CI4: 인증 + 룸 토큰(JWT) 발급 API 엔드포인트 구현
2. [x] coturn 셀프호스팅 설정 (docker-compose, HMAC secret 연동) — 인프라만 구축, LiveKit 연동은 보류(4절)
3. [x] LiveKit 셀프호스팅 배포 (docker-compose/k8s), CI4와 토큰 검증 연동
4. [x] 리눅스 수신 데몬: GStreamer webrtcbin 파이프라인으로 프로토타입 작성
5. [x] 데몬 systemd 서비스화 (자동 재시작, 헬스체크 엔드포인트)
6. [x] 온프레미스 인프라: 방화벽·DDNS·Caddy 리버스 프록시(자동 HTTPS) 스크립트/설정 — 공유기 포트포워딩·DDNS 계정·도메인 연결은 수동(`infra/README.md` 참고)
7. [x] 통합 테스트: 자동화 가능한 부분(토큰 재발급) 구현 + 실배포 후 수동 수행할 런북 작성 — 공중망 NAT 통과·지연 측정 자체는 실제 배포·모바일 기기가 필요해 이 환경에서 수행 불가([`INTEGRATION-TEST-RUNBOOK.md`](INTEGRATION-TEST-RUNBOOK.md) 참고)
8. [x] 외부용 앱: 로그인 화면 + CI4 인증 연동
9. [x] 외부용 앱: 목소리 전송(마이크 캡처 → LiveKit 퍼블리시) UI
10. [x] 외부용 앱: 데이터 채널 수신 및 리턴 메시지 표시 UI — 스키마 확정(#9), 데몬 발신 구현 완료(#12)
11. [x] 양방향 인터콤: 자택 마이크 연결 후 역방향 오디오 경로 설계·구현(이슈 #11, 11절 참고)

## 8. 참고 기술 스택 요약
- 백엔드: CodeIgniter 4 (PHP 8.2+), PHPUnit, PHPStan
- SFU: LiveKit (Go), 내장 TURN 사용. 토큰 발급은 CI4가 `firebase/php-jwt`로 LiveKit 액세스 토큰(HS256) 직접 생성
- TURN(미사용, 보류): coturn
- 리눅스 데몬: Python + PyGObject(GStreamer), `livekitwebrtcsrc`(gst-plugins-rs), prometheus_client, systemd
- 외부용 앱: 바닐라 JS + Vite + `livekit-client`, Vitest/ESLint/Prettier
- 네트워크/TLS: Caddy(자동 Let's Encrypt 리버스 프록시), ufw, DDNS(제공자 비종속 curl 스크립트)
- 인프라: 온프레미스 자택 서버(우분투), CI/CD 없이 로컬 검증(저장소 공통 규칙)

## 9. 리눅스 서버 물리 배치
- 위치: 자택 내부(온프레미스). coturn/LiveKit/CI4 API·리눅스 수신 데몬 **모두 자택 서버에 온프레미스로 설치**한다(AWS 등 클라우드 미사용, 5절 참고).
- 스피커: 연결 완료 — 1차 목표(클라이언트 → 자택 스피커, 단방향)의 출력 장치.
- 마이크: **연결 완료(USB)**. 양방향 인터콤(자택 마이크 → 클라이언트) 역방향 경로 구현됨 — 11절 참고. 캡처 디바이스는 `arecord -l`로 확인해 데몬 `RTIC_AUDIO_SOURCE`에 지정한다.

## 10. 외부용 앱 기능 스펙
| 기능 | 설명 | 처리 방식 |
|---|---|---|
| 로그인 | 앱 사용자 인증 | CI4 API 인증 → 룸 입장 JWT 발급(3절 1단계) |
| 목소리 전송 | 마이크 캡처 후 자택 스피커로 실시간 송신 | 기존 처리 흐름(3절) 그대로 재사용 |
| 리턴 메시지 표시 | 리눅스 데몬 측 상태·텍스트 메시지를 앱 UI에 표시 | LiveKit **텍스트 스트림**(데이터 채널), 토픽 `rtic.status`, 페이로드 `{type, message, ts}`(이슈 #9에서 확정, `web/README.md` 참고). 데몬 발신은 `rtic_daemon/status_reporter.py`(#12)가 담당 |

구현: [`web/`](web/)(바닐라 JS + livekit-client + Vite). CI4 API와 오리진이 다르므로
`app/Config/Cors.php` + `app/Config/Filters.php`에 CORS 설정을 추가했다(`.env`의
`cors.allowedOrigins`).

## 11. 양방향 인터콤 (이슈 #11 — 구현 완료)
자택 서버에 마이크가 연결되면 데몬이 로컬 오디오를 캡처해 GStreamer로 LiveKit에 퍼블리시하고, 앱이 이를 재생하는 역방향 경로다. 기존 단방향 구조를 **대칭 복제**해 구현했다.

- **데몬(송신)**: `RTIC_MIC_ENABLED=true`이면 수신(스피커) 파이프라인과 함께 송신(마이크) 파이프라인을 같은 GLib 루프에서 돌린다. `<audio_source> ! queue ! audioconvert ! audioresample ! livekitwebrtcsink` 구조로, 마이크 캡처 소스는 `RTIC_AUDIO_SOURCE`(예: `alsasrc device=plughw:1,0`)로 지정한다. Opus 인코딩은 `livekitwebrtcsink`가 내부 처리하며(별도 opusenc 불필요), 오디오 수신기와 identity 충돌을 피하려 `<identity>-mic` 별도 참가자로 접속한다. 수신·송신은 서로 독립이라 한 방향이 끊겨도 다른 방향은 유지된다 — 각 파이프라인이 에러·EOS 시 프로세스를 죽이지 않고 자기만 in-process 백오프 재시작한다(6절 참고).
- **앱(수신)**: `RoomEvent.TrackSubscribed`로 데몬 오디오 트랙을 받아 재생한다(`web/src/audioPlayback.js`). `room.connect`의 기본 `autoSubscribe=true`로 자동 구독되므로 재생 element만 붙인다.
- **토큰**: `LiveKitAccessTokenService`가 발급하는 앱 토큰은 이미 `canPublish`/`canSubscribe`를 모두 포함해 양방향에 별도 변경이 필요 없다.
- **검증**: 실제 마이크 캡처·오디오 왕복 지연은 실배포·실물 마이크가 필요해 [`INTEGRATION-TEST-RUNBOOK.md`](INTEGRATION-TEST-RUNBOOK.md)의 수동 절차로 확인한다. `livekitwebrtcsink`의 코덱 협상은 실서버(`gst-inspect-1.0 livekitwebrtcsink`)에서 최종 확인한다.
