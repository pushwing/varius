# 실시간 인터콤 (외부 공중인터넷 → 리눅스 스피커) 아키텍처 스펙

## 1. 목표
외부 공중 인터넷에서 접속한 클라이언트(웹/앱)의 음성을 리눅스 서버에 연결된 물리 스피커로
실시간(지연 200~300ms 이내)으로 전달한다. WebRTC 기반, NAT 통과를 위한 TURN 필수.

## 2. 전체 구성 요소

| 구성 요소 | 역할 | 기술 |
|---|---|---|
| 클라이언트 | 마이크 캡처, 룸 접속 | 웹(getUserMedia) / 앱 |
| CI4 API | 인증, 룸 토큰(JWT) 발급, 디바이스/이력 관리 | PHP 8.2+, CodeIgniter 4 |
| SFU / 시그널링 | WebRTC 시그널링, 미디어 라우팅 | LiveKit (셀프호스팅 1순위), 대안: Janus |
| TURN 서버 | NAT 통과, 미디어 릴레이 | coturn (UDP 3478 + 443 TLS fallback) |
| 리눅스 수신 데몬 | SFU에 참가자로 접속, 오디오 디코딩 후 스피커 출력 | GStreamer webrtcbin → ALSA/PulseAudio, systemd 상주 |

## 3. 처리 흐름
1. 클라이언트가 CI4 API에 인증 요청 → 짧은 만료시간의 룸 입장 JWT 발급
2. 클라이언트가 발급받은 토큰으로 LiveKit(SFU)에 접속, WebRTC 오퍼/앤서 교환
3. NAT 통과 실패 시 coturn을 통해 미디어 릴레이 (HMAC 기반 단기 TTL credential)
4. 리눅스 수신 데몬은 서버가 지정한 특정 room에만 참가자로 join (임의 room 지정 불가)
5. 데몬이 수신한 Opus 오디오를 GStreamer 파이프라인으로 디코딩 → ALSA로 실시간 출력

## 4. 보안 요구사항
- WebRTC 미디어는 DTLS-SRTP로 기본 암호화됨
- CI4가 발급하는 룸 토큰은 짧은 만료시간(TTL) 적용
- coturn credential은 `use-auth-secret` + HMAC 방식, 수십 초~수 분 TTL로 재사용 방지
- 리눅스 데몬의 room 접근은 서버 측에서 강제 (클라이언트 임의 지정 금지)

## 5. AWS 배포 가이드
- coturn, LiveKit: EC2 또는 ECS + **NLB**(UDP 트래픽 처리 위해 ALB 아닌 NLB 사용)
- CI4 API: 기존 ECS/EKS 구성 유지, 룸 토큰 발급 엔드포인트만 추가
- 초기 단일 리전 → 사용자 지역 확대 시 TURN/SFU 리전별 다중화 고려 (지연 민감)

## 6. 리눅스 수신 데몬 요구사항
- 네트워크 끊김 시 자동 재접속 (지수 백오프)
- SFU 연결 상태 헬스체크 노출 (CloudWatch/Prometheus 연동)
- 오디오 출력 실패 시 자동 재시작: `systemd` `Restart=on-failure`

## 7. 개발 순서 제안 (Claude Code 작업 단위)
1. [ ] CI4: 인증 + 룸 토큰(JWT) 발급 API 엔드포인트 구현
2. [ ] coturn 셀프호스팅 설정 (docker-compose, HMAC secret 연동)
3. [ ] LiveKit 셀프호스팅 배포 (docker-compose/k8s), CI4와 토큰 검증 연동
4. [ ] 리눅스 수신 데몬: GStreamer webrtcbin 파이프라인으로 프로토타입 작성
5. [ ] 데몬 systemd 서비스화 (자동 재시작, 헬스체크 엔드포인트)
6. [ ] AWS 인프라: NLB + coturn/LiveKit EC2 구성, 보안그룹(UDP 3478 등) 설정
7. [ ] 통합 테스트: 공중망 환경(모바일 데이터 등)에서 NAT 통과 시나리오 검증

## 8. 참고 기술 스택 요약
- 백엔드: CodeIgniter 4 (PHP 8.2+), PHPUnit, PHPStan
- SFU: LiveKit (Go)
- TURN: coturn
- 리눅스 데몬: GStreamer (webrtcbin), systemd
- 인프라: AWS (EC2/ECS, NLB), GitHub Actions CI/CD
