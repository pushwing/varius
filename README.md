# varius

여러 프로젝트를 모아놓은 모노레포입니다. 프로젝트마다 독립된 서브디렉토리를 가지며,
각 서브디렉토리는 자체 `AGENTS.md`(Codex 작업 규칙)와 `README.md`(프로젝트 개요)를 보유합니다.

프로젝트는 서로 독립적으로 설치·실행·배포합니다. 공통 저장소 규칙은 루트
[`AGENTS.md`](AGENTS.md)를, 각 프로젝트의 사용 방법은 해당 디렉터리 문서를 참고하세요.

저장소 전체에 적용되는 컨벤션(브랜치 전략, CI/CD 정책, 디렉토리 구조 규칙 등)은
[`AGENTS.md`](AGENTS.md)를 참고하세요.

## 프로젝트 목록

| 디렉토리 | 설명 |
|---|---|
| [`rtic/`](rtic/) | Realtime Intercom — 외부 공중 인터넷 클라이언트와 자택 리눅스 서버 간 음성을 실시간으로 주고받는 WebRTC 기반 **양방향** 인터콤 시스템(클라이언트 → 자택 스피커, 자택 마이크 → 클라이언트) |
| [`Iter/`](Iter/) | Google Photos GPS 동선 시각화 — Google Takeout 사진 zip·기기 위치기록(Timeline.json)에서 GPS를 추출해 지도에 날짜별 이동 동선으로 시각화하고, 시간표·여행 단위 통계·공유 기능을 제공 |
| [`Pagus/`](Pagus/) | 파주 로컬 맛집 지도 — Kakao 지도·검색으로 공개 맛집을 찾고, 맛집 상세·사진·방문 후기·문의와 운영자 관리 기능을 제공 |

## 프로젝트 시작하기

각 프로젝트의 의존성과 실행 환경이 다르므로 작업하려는 디렉터리로 이동한 뒤 해당 문서를 확인합니다.

- [`rtic/README.md`](rtic/README.md): WebRTC 인터콤 실행 안내
- [`Iter/README.md`](Iter/README.md): GPS 동선 시각화 실행 안내
- [`Pagus/README.md`](Pagus/README.md): 제품 개요와 현재 구현 범위
- [`Pagus/SETUP.md`](Pagus/SETUP.md): Pagus의 PHP·MySQL·Kakao API 로컬 셋업과 검증 절차
