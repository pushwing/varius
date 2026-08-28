# varius

여러 프로젝트를 모아놓은 모노레포입니다. 프로젝트마다 독립된 서브디렉토리를 가지며,
각 서브디렉토리는 작업 도구별 규칙 문서와 `README.md`(프로젝트 개요)를 보유합니다.

- Codex로 작업할 때는 루트 [`AGENTS.md`](AGENTS.md)를 먼저 읽고, 작업 대상 프로젝트의 `AGENTS.md`를 참고합니다.
- Claude Code로 작업할 때는 루트 [`CLAUDE.md`](CLAUDE.md)를 먼저 읽고, 작업 대상 프로젝트의 `CLAUDE.md`를 참고합니다.

두 문서는 같은 저장소의 작업 규칙을 Codex와 Claude Code 환경에 맞게 각각 기록한 것입니다.

프로젝트는 서로 독립적으로 설치·실행·배포합니다. 공통 저장소 규칙은 사용하는 도구에 따라 루트
`AGENTS.md` 또는 `CLAUDE.md`를, 각 프로젝트의 사용 방법은 해당 디렉터리 문서를 참고하세요.

저장소 전체에 적용되는 컨벤션(브랜치 전략, CI/CD 정책, 디렉토리 구조 규칙 등)은
Codex에서는 [`AGENTS.md`](AGENTS.md), Claude Code에서는 [`CLAUDE.md`](CLAUDE.md)를 참고하세요.

## 프로젝트 목록

| 디렉토리 | 설명 |
|---|---|
| [`rtic/`](rtic/) | Realtime Intercom — 외부 공중 인터넷 클라이언트와 자택 리눅스 서버 간 음성을 실시간으로 주고받는 WebRTC 기반 **양방향** 인터콤 시스템(클라이언트 → 자택 스피커, 자택 마이크 → 클라이언트) |
| [`Iter/`](Iter/) | Google Photos GPS 동선 시각화 — Google Takeout 사진 zip·기기 위치기록(Timeline.json)에서 GPS를 추출해 지도에 날짜별 이동 동선으로 시각화하고, 시간표·여행 단위 통계·공유 기능을 제공. [실제 운영 서비스](https://iter.aivance.kr/) |
| [`Pagus/`](Pagus/) | 파주 로컬 맛집 지도 — Kakao 지도·검색으로 공개 맛집을 찾고, 맛집 상세·사진·방문 후기·문의와 운영자 관리 기능을 제공. [실제 운영 서비스](https://pagus.aivance.kr) |

## 프로젝트 시작하기

각 프로젝트의 의존성과 실행 환경이 다르므로 작업하려는 디렉터리로 이동한 뒤 사용하는 도구에 맞는
규칙 문서와 프로젝트 문서를 확인합니다.

| 프로젝트 | Codex 작업 규칙 | Claude Code 작업 규칙 | 사용 안내 |
|---|---|---|---|
| `rtic/` | [`AGENTS.md`](rtic/AGENTS.md) | [`CLAUDE.md`](rtic/CLAUDE.md) | [`README.md`](rtic/README.md) |
| `Iter/` | [`AGENTS.md`](Iter/AGENTS.md) | [`CLAUDE.md`](Iter/CLAUDE.md) | [`README.md`](Iter/README.md) |
| `Pagus/` | [`AGENTS.md`](Pagus/AGENTS.md) | [`CLAUDE.md`](Pagus/CLAUDE.md) | [`README.md`](Pagus/README.md), [`SETUP.md`](Pagus/SETUP.md) |
