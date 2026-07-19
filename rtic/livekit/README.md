# rtic LiveKit (SFU)

WebRTC 시그널링·미디어 라우팅을 담당하는 LiveKit을 자택 서버에 온프레미스로
셀프호스팅합니다. 단일 노드 구성이며 내장 TURN을 사용합니다(별도 coturn은
`../coturn/`에 있으나 현재 미연동 — `../ARCHITECTURE.md` 4절 참고).

## 사전 준비 — Docker 설치 (자택 서버에 Docker가 없는 경우)

자택 서버(Ubuntu 24.04 기준)에 Docker가 없다면 먼저 설치합니다. LiveKit 자체가
아니라 **이 서버 전체의 배포 방식**(coturn·LiveKit 모두 docker-compose로
관리)에 필요한 전제 조건입니다.

```bash
# 구버전/충돌 패키지 제거(있는 경우만)
sudo apt-get remove -y docker docker-engine docker.io containerd runc 2>/dev/null || true

sudo apt-get update
sudo apt-get install -y ca-certificates curl gnupg

# Docker 공식 GPG 키 등록
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg

# Docker 공식 저장소 등록
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \
  $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

# sudo 없이 docker 명령을 쓰려면(재로그인 필요)
sudo usermod -aG docker "$USER"
```

설치 확인:

```bash
docker --version
docker compose version
```

> 참고: `docker compose`(플러그인, 하이픈 없음)를 쓰는지 확인하세요. 구버전
> `docker-compose`(하이픈 있는 standalone)와 명령어 자체는 호환되지만, 위
> 설치 방법은 플러그인 버전을 설치합니다. 이 저장소의 `docker-compose.yml`
> 파일들은 두 방식 모두와 호환됩니다.

## LiveKit 설정

```bash
cd rtic/livekit
cp .env.example .env
```

새 API key/secret 쌍을 생성해 `.env`에 넣습니다:

```bash
docker run --rm livekit/livekit-server:v1.9 generate-keys
```

`.env` 형식은 `LIVEKIT_KEYS=<key>: <secret>`(콜론 뒤 공백 필수)입니다. 같은
값을 CI4 API의 `.env`(`livekit.apiKey`/`livekit.apiSecret`)에도 넣어야
토큰 발급·검증이 서로 맞습니다.

```bash
docker compose up -d
docker compose logs -f   # 정상 기동 확인 후 Ctrl+C
```

## 설정 파일 설명 — `livekit.yaml`

```yaml
port: 7880              # HTTP/WebSocket 시그널링 포트
rtc:
  tcp_port: 7881         # TURN-TLS 폴백용 TCP 포트
  port_range_start: 50000
  port_range_end: 50100  # ICE 미디어 UDP 릴레이 범위
  use_external_ip: true  # STUN으로 공인 IP 감지(온프레미스 실서버 배포용)
turn:
  enabled: true           # LiveKit 내장 TURN 사용(coturn 대체)
  udp_port: 3478
```

- 단일 서버 구성이라 다중 노드 상태 동기화용 Redis는 두지 않았다.
- 운영 배포에서 공인 도메인을 확보하면 TURN TLS(443)도 활성화할 수 있다
  (`turn.tls_port`/`turn.domain`, 파일 내 주석 참고).
- CI4 API·LiveKit 시그널링 자체의 HTTPS는 LiveKit이 아니라 **Caddy
  리버스 프록시**가 처리한다(`../infra/caddy/Caddyfile`) — LiveKit은
  내부(127.0.0.1:7880)에만 바인딩되는 구조.

## 배포 전체 순서

포트포워딩·방화벽·Caddy(자동 HTTPS)·CI4/데몬 systemd 상주까지 포함한 전체
온프레미스 배포 순서는 [`../infra/README.md`](../infra/README.md)를 참고한다.

## 로컬 동작 확인

```bash
curl -s http://localhost:7880/  # LiveKit HTTP 헬스체크(연결만 되면 됨)
```

CI4 API(`php spark serve`)를 띄우고 `/api/v1/tokens`에 로그인 요청을 보내면
LiveKit 접속 토큰이 발급되는지 확인할 수 있다(`../CLAUDE.md`, `../ARCHITECTURE.md`
3절 참고).
