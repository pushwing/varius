# rtic 통합 테스트 런북 — 공중망 NAT 통과 시나리오 (이슈 #10)

## 왜 런북인가

이 이슈의 핵심 검증 대상(실제 공중 인터넷 NAT 뒤 클라이언트 접속, 실제 지연
측정)은 **실제로 배포된 서버 + 공인 도메인 + 실제 모바일 기기**가 있어야
수행할 수 있다. 개발 환경(로컬 Docker, 샌드박스)에서는 자동화·검증할 수
없으므로, 실제 배포 후 사람이 직접 수행하는 체크리스트로 남긴다.

자동화 가능했던 부분(룸 토큰 재발급이 항상 새 토큰을 내는지)은
`rtic/tests/feature/Api/V1/AuthTokenTest.php::testReloginIssuesFreshIndependentToken`와
`rtic/tests/unit/Libraries/LiveKitAccessTokenServiceTest.php::testTokenHasUniqueJtiAcrossCalls`로
이미 자동화돼 있다 — 이 작업 중 **같은 초(second)에 재로그인하면 완전히
동일한 토큰이 발급되던 버그**를 발견해 `jti`(고유 토큰 ID) 클레임을
추가해 고쳤다(`app/Libraries/LiveKitAccessTokenService.php`).

## 사전 조건 (수동, `rtic/infra/README.md` 참고)

- [ ] 공유기 포트포워딩 완료(TCP 80/443, UDP 3478, UDP 50000–50100)
- [ ] DDNS 또는 고정 IP로 도메인이 서버를 가리킴
- [ ] Caddy가 실제 Let's Encrypt 인증서를 발급받아 `https://` 로 CI4 API·LiveKit 시그널링에 접근 가능
- [ ] `rtic-api`, `rtic-daemon`, LiveKit, (필요 시 `rtic-ddns.timer`) systemd/docker 서비스가 모두 기동 중
- [ ] 자택 서버 스피커에 실제 오디오 출력 장치 연결됨

## 시나리오 1 — 모바일 데이터에서 NAT 통과

1. 자택 Wi-Fi가 **아닌** 모바일 데이터(LTE/5G)로 전환한 휴대폰에서 웹 앱
   URL(`https://<RTIC_API_DOMAIN 또는 앱 호스팅 도메인>`)에 접속한다.
2. 로그인 → 브라우저가 마이크 권한을 요청하면 허용한다.
3. "연결됨 — room: rtic-home" 상태가 표시되는지 확인한다.
4. 마이크 켜기 버튼을 눌러 말을 하고, 자택 스피커에서 실시간으로
   소리가 나오는지 확인한다(지연 체감 포함).
5. **실패 시 점검**: 브라우저 개발자 도구 콘솔에서 ICE 연결 상태
   로그(`connection state changed`)를 확인 — `disconnected`/`failed`가
   반복되면 LiveKit 내장 TURN(UDP 3478) 포워딩이 실제로 열려 있는지
   재확인한다.

- [ ] 통과
- [ ] 실패 — 원인:

## 시나리오 2 — 지연(latency) 측정

목표: 200~300ms 이내 (`ARCHITECTURE.md` 1절).

1. 크롬 기준 `chrome://webrtc-internals`를 열어둔 채 앱에 접속·연결한다.
2. 연결된 PeerConnection의 `candidate-pair` 통계에서 `currentRoundTripTime`을
   확인한다(초 단위 — 1000을 곱해 ms로 환산).
3. 간이 측정(계측 도구 없이): 자택 스피커 옆에서 휴대폰으로 박수를 치고,
   스피커에서 소리가 나올 때까지의 체감 지연을 스톱워치 앱 등으로 3회
   측정해 평균을 낸다.
4. `candidate-pair`의 `localCandidateType`/`remoteCandidateType`이
   `relay`(TURN 릴레이 경유)인지 `srflx`/`host`(직접 연결)인지도 함께
   기록한다 — 릴레이 경유 시 지연이 더 크게 나오는 게 정상이다.

- [ ] RTT(ms):
- [ ] candidate 타입(local/remote):
- [ ] 200~300ms 목표 충족 여부:

## 시나리오 3 — 룸 토큰 TTL 만료/재발급

자동화된 부분(재발급 시 항상 새 토큰 발급)은 위에서 언급한 PHPUnit
테스트로 커버된다. 아래는 실제 배포 환경에서 수동으로 재확인할 부분이다.

1. 로그인해 토큰을 발급받은 뒤 `livekit.tokenTtl`(기본 300초)이 지날
   때까지 아무 것도 하지 않고 기다린다.
2. 만료 후 연결이 실제로 끊기는지(또는 애초에 재접속 로직이 없으므로
   앱을 새로고침해 재로그인이 정상적으로 되는지) 확인한다.
3. **참고(이 작업 중 로컬에서 발견한 사실)**: LiveKit은 `exp` 클레임에
   대해 어느 정도 클럭 스큐 유예를 둔다 — 로컬 검증에서 만료 후 28초
   지난 토큰은 여전히 통과했고, 1시간 지난 토큰은 정확히
   `token is expired (exp)`로 거부됐다. 정확한 유예 폭은 확인하지
   않았으나(초 단위가 아니라 분 단위로 추정), 가족용 홈 인터콤
   규모에서는 보안상 문제가 되지 않는 범위로 판단한다. 재현이
   필요하면 `LiveKitAccessTokenService`와 동일한 알고리즘으로 임의
   만료시각의 토큰을 만들어 LiveKit Twirp API 또는 실제 룸 접속으로
   테스트한다(`rtic/ARCHITECTURE.md` 참고).

- [ ] 만료 후 재로그인 정상 동작
- [ ] 특이사항:

## 결과 요약

| 시나리오 | 결과 | 비고 |
|---|---|---|
| NAT 통과(모바일 데이터) | | |
| 지연 200~300ms | | |
| 토큰 만료/재발급 | | |

테스트 수행일: \_\_\_\_\_\_\_\_ / 수행자: \_\_\_\_\_\_\_\_
