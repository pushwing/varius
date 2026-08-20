# rtic (Realtime Intercom) 프로젝트 가이드

> 저장소 공통 규칙은 [`../AGENTS.md`](../AGENTS.md)를 따른다. 이 문서는 `rtic/`의
> 스택, 아키텍처, 검증 규칙만 다룬다.

## 프로젝트 개요

외부 공중 인터넷의 클라이언트(웹/앱) 음성을 리눅스 서버의 물리 스피커로 실시간(200~300ms 이내)
전달하는 WebRTC 기반 인터콤 시스템이다. 상세 아키텍처는 [`ARCHITECTURE.md`](ARCHITECTURE.md)를 참고한다.

## 기술 스택

- **API**: CodeIgniter 4 (PHP 8.2+) — 인증, LiveKit 액세스 토큰(JWT) 발급, 디바이스·이력 관리
- **SFU/시그널링**: LiveKit 셀프호스팅, 내장 TURN 사용
- **TURN**: LiveKit 내장 TURN 사용. 별도 coturn(`use-auth-secret` + HMAC)은 인프라만 구축된 미연동 상태다. 자세한 내용은 `ARCHITECTURE.md` 4절 참고.
- **리눅스 수신 데몬**: Python + PyGObject(GStreamer, `livekitwebrtcsrc`) → ALSA/PulseAudio, systemd 상주 (`daemon/` 참고)
- **외부용 앱**: 바닐라 JS + Vite + `livekit-client` (`web/` 참고). 가족용 인터콤 규모에서는 React 같은 프레임워크를 도입하지 않고 최소 구성으로 유지한다.
- **인프라**: 온프레미스 자택 우분투 서버. coturn, LiveKit, CI4 API, 수신 데몬을 모두 이 서버에 설치하며 클라우드는 사용하지 않는다.

PHP(CodeIgniter 4) 코드는 프로젝트 및 상위 작업 지침의 PHP 보안·스타일·테스트 규칙을 따른다.
GStreamer 데몬 등 PHP 외 컴포넌트는 해당 언어의 관례를 따른다.

## 로컬 검증

CI/CD가 없으므로 머지 전에 관련 영역의 검증을 로컬에서 직접 실행한다.

- PHP(CI4): `composer ci` (CS Fixer → PHPStan → PHPUnit). `composer check`는 CS Fixer를 포함하지 않으므로 사용하지 않는다.
- 리눅스 데몬(`daemon/`): `ruff check`, `ruff format --check`, `pytest`. 실제 서브프로세스로 시그널, 종료 코드, 헬스체크를 검증한다.
- 네트워크 인프라(`infra/`): 셸 스크립트는 `shellcheck`, `caddy/Caddyfile`은 `caddy validate`.
- 외부용 앱(`web/`): `npm run lint`, `npm run format:check`, `npm run test` 또는 `npm run ci`.
- API 엔드포인트 또는 데몬 프로세스 같은 런타임 표면을 변경하면 테스트 외에 실제 구동도 확인한다.

공유기 포트포워딩, DDNS 계정, 도메인 연결은 실제 자택 네트워크가 필요한 수동 작업이므로 자동 검증 대상이 아니다.

## 보안 유의사항

- LiveKit 액세스 토큰(JWT)은 짧은 TTL을 적용하고 VideoGrant의 room, publish, subscribe, 데이터 채널 권한을 최소로 부여한다.
- 리눅스 데몬의 room 접근은 서버에서 강제한다. 클라이언트가 임의 room을 지정할 수 없어야 한다.
- 상세 보안 설계는 `ARCHITECTURE.md` 4절을 참고한다.
