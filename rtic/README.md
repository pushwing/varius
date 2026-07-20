# rtic — Realtime Intercom

외부 공중 인터넷에서 접속한 클라이언트(웹/앱)와 자택 리눅스 서버 사이에서 음성을
실시간(지연 200~300ms 이내)으로 주고받는 WebRTC 기반 **양방향** 인터콤 시스템입니다.
클라이언트 음성 → 자택 스피커, 자택 마이크 → 클라이언트 재생 양방향을 지원합니다.

## 구성

- **CI4 API** — 인증, LiveKit 액세스 토큰(JWT) 발급
- **LiveKit (SFU)** — WebRTC 시그널링·미디어 라우팅, 내장 TURN 사용
- **coturn (TURN)** — NAT 통과. 인프라만 구축된 상태이며 현재는 LiveKit 내장 TURN을 대신 사용 중(미연동, `ARCHITECTURE.md` 4절 참고)
- **리눅스 데몬** — Python + GStreamer로 클라이언트 오디오를 디코딩해 스피커 출력 + (양방향) 자택 마이크 오디오를 인코딩해 LiveKit에 퍼블리시([`daemon/`](daemon/) 참고)
- **네트워크/TLS** — Caddy 리버스 프록시(자동 Let's Encrypt), ufw 방화벽, DDNS([`infra/`](infra/) 참고)
- **외부용 앱** — 바닐라 JS + livekit-client, 로그인·목소리 전송·자택 마이크 오디오 수신 재생·리턴 메시지 표시([`web/`](web/) 참고)

위 구성 요소는 모두 **자택 서버(우분투)에 온프레미스로 설치**됩니다. AWS 등 클라우드 인프라는 사용하지 않습니다.

상세 아키텍처와 개발 순서는 [`ARCHITECTURE.md`](ARCHITECTURE.md)를,
Claude Code 작업 규칙은 [`CLAUDE.md`](CLAUDE.md)를 참고하세요.

## 개발 상태

`ARCHITECTURE.md`의 "개발 순서 제안"에 따라 순차 구현 중입니다.

- [x] CI4 인증 + LiveKit 액세스 토큰 발급 API
- [x] coturn 온프레미스 셀프호스팅 인프라(현재 미연동)
- [x] LiveKit 온프레미스 배포 + CI4 토큰 검증 연동
- [x] 리눅스 수신 데몬(GStreamer 프로토타입 + systemd 서비스화)
- [x] 온프레미스 네트워크 인프라(방화벽·DDNS·Caddy 자동 HTTPS)
- [x] 외부용 앱(로그인·목소리 전송·리턴 메시지 표시)
- [x] 리눅스 데몬 리턴 메시지 발신(LiveKit 텍스트 스트림)
- [x] 통합 테스트 — 토큰 재발급 자동화 테스트 + 실배포 후 수동 검증 런북([`INTEGRATION-TEST-RUNBOOK.md`](INTEGRATION-TEST-RUNBOOK.md))
- [x] **양방향 인터콤** — 자택 마이크 → 앱 역방향 오디오 경로(데몬 퍼블리시 + 앱 수신 재생), 실서버 동작 확인(#11)
