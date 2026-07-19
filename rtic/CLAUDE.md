# rtic (Realtime Intercom) 프로젝트 가이드

> 저장소 공통 규칙은 [`../CLAUDE.md`](../CLAUDE.md)에서 상속된다(PR 없이 직접 머지, CI/CD 없음·로컬 검증).
> 이 파일은 `rtic/` 프로젝트 고유의 스택·아키텍처·규칙만 다룬다.

## 프로젝트 개요

외부 공중 인터넷의 클라이언트(웹/앱) 음성을 리눅스 서버의 물리 스피커로 실시간(200~300ms 이내) 전달하는
WebRTC 기반 인터콤 시스템. 상세 아키텍처는 [`ARCHITECTURE.md`](ARCHITECTURE.md) 참고.

## 기술 스택

- **API**: CodeIgniter 4 (PHP 8.2+) — 인증, LiveKit 액세스 토큰(JWT) 발급, 디바이스/이력 관리
- **SFU/시그널링**: LiveKit (셀프호스팅, 내장 TURN 사용)
- **TURN**: LiveKit 내장 TURN 사용. 별도 coturn(`use-auth-secret` + HMAC)은 인프라만 구축된 채 미연동 상태(`ARCHITECTURE.md` 4절)
- **리눅스 수신 데몬**: Python + PyGObject(GStreamer, `livekitwebrtcsrc`) → ALSA/PulseAudio, systemd 상주(`daemon/` 참고)
- **인프라**: 온프레미스 자택 서버(우분투). coturn/LiveKit/CI4 API·수신 데몬 모두 이 서버에 설치하며, 클라우드(AWS 등)는 사용하지 않는다. 상세는 [`ARCHITECTURE.md`](ARCHITECTURE.md) 5·9절 참고.

이 프로젝트 내 PHP(CodeIgniter 4) 코드는 부모 저장소들의 전역 PHP 규칙
([`~/.claude/rules/code-style.md`](~/.claude/rules/code-style.md),
[`~/.claude/rules/security.md`](~/.claude/rules/security.md),
[`~/.claude/rules/testing.md`](~/.claude/rules/testing.md),
[`~/.claude/rules/api-design.md`](~/.claude/rules/api-design.md))을 그대로 따른다.
GStreamer 데몬 등 PHP가 아닌 컴포넌트는 해당 언어의 관례를 따른다.

## 로컬 검증

- CI/CD가 없으므로 머지 전 아래를 **로컬에서** 직접 실행해 확인한다.
  - PHP(CI4) 파트: `composer ci` (CS Fixer → PHPStan → PHPUnit). `composer check`는 CS Fixer를 빠뜨리므로 사용하지 않는다.
  - 리눅스 데몬(`daemon/`): `ruff check` + `ruff format --check` + `pytest`(실제 서브프로세스로 시그널·종료 코드·헬스체크 검증, `daemon/README.md` 참고)
  - 네트워크 인프라(`infra/`): 셸 스크립트는 `shellcheck`, `caddy/Caddyfile`은 `caddy validate`(`infra/README.md` 참고). 공유기 포트포워딩·DDNS 계정·도메인 연결은 실제 자택 네트워크가 있어야 하는 수동 작업이라 이 저장소에서 자동 검증할 수 없다.
- 런타임 표면(API 엔드포인트, 데몬 프로세스)이 있는 변경은 테스트만으로 끝내지 않고 실제 구동까지 확인한다.

## 보안 유의사항

- LiveKit 액세스 토큰(JWT)은 짧은 TTL, VideoGrant로 room·publish·subscribe·데이터 채널 권한만 최소 부여.
- 리눅스 데몬의 room 접근은 서버 측에서 강제 — 클라이언트가 임의 room을 지정할 수 없게 한다.
- 상세는 [`ARCHITECTURE.md`](ARCHITECTURE.md) 4절 참고.
