# rtic — Realtime Intercom

외부 공중 인터넷에서 접속한 클라이언트(웹/앱)의 음성을 리눅스 서버에 연결된 물리 스피커로
실시간(지연 200~300ms 이내)으로 전달하는 WebRTC 기반 인터콤 시스템입니다.

## 구성

- **CI4 API** — 인증, 룸 토큰(JWT) 발급
- **LiveKit (SFU)** — WebRTC 시그널링·미디어 라우팅
- **coturn (TURN)** — NAT 통과
- **리눅스 수신 데몬** — GStreamer로 오디오 디코딩 후 스피커 출력

상세 아키텍처와 개발 순서는 [`ARCHITECTURE.md`](ARCHITECTURE.md)를,
Claude Code 작업 규칙은 [`CLAUDE.md`](CLAUDE.md)를 참고하세요.

## 개발 상태

아직 초기 설계 단계이며 코드는 없습니다. `ARCHITECTURE.md`의 "개발 순서 제안"에 따라
CI4 인증/토큰 발급 API부터 순차적으로 구현할 예정입니다.
