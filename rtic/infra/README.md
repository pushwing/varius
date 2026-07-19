# rtic 온프레미스 네트워크 인프라

coturn(현재 미사용)/LiveKit/CI4 API를 자택 서버에서 외부 공중 인터넷에
노출하기 위한 방화벽·DDNS·리버스 프록시(TLS) 설정입니다.

> ⚠️ 이 디렉토리는 스크립트/설정 파일만 제공합니다. **공유기 포트포워딩,
> DDNS 계정 가입, 도메인 DNS 레코드 연결은 실제 자택 네트워크·계정이
> 있어야 하는 수동 작업**이라 자동화·검증할 수 없습니다. 아래 각 절에서
> 무엇이 자동화됐고 무엇이 수동인지 명시합니다.

## 1. 공유기 포트포워딩 (수동)

공유기 관리 화면에서 서버의 사설 IP로 아래 포트를 포워딩한다.

| 포트 | 프로토콜 | 용도 |
|---|---|---|
| 80 | TCP | Caddy Let's Encrypt HTTP-01 챌린지 |
| 443 | TCP | Caddy HTTPS(CI4 API + LiveKit 시그널링) |
| 3478 | UDP | LiveKit 내장 TURN |
| 50000–50100 | UDP | LiveKit ICE 미디어 릴레이 (`rtic/livekit/livekit.yaml`의 `rtc.port_range_start/end`와 반드시 일치) |

coturn(`rtic/coturn/`)은 현재 LiveKit 내장 TURN으로 대체돼 미사용 상태라
포워딩하지 않는다(`ARCHITECTURE.md` 4절 참고). 재도입하면 coturn의
UDP 3478/TCP 443 포워딩을 별도로 추가해야 한다.

## 2. 방화벽 — `firewall/setup-ufw.sh` (자동, 서버에서 실행)

서버 자체의 ufw 방화벽을 위 표와 동일한 포트만 허용하도록 설정한다.

```bash
sudo bash rtic/infra/firewall/setup-ufw.sh
```

Ubuntu 24.04 컨테이너에서 실제 실행해 규칙이 올바르게 적용됨을 확인했다.

## 3. DDNS — `ddns/update-ddns.sh` + `systemd/rtic-ddns.{service,timer}` (자동)

고정 공인 IP가 아니면 DDNS로 도메인이 현재 IP를 가리키게 유지해야 한다.
특정 서비스에 종속되지 않도록 "GET 요청 URL 하나로 갱신되는" 방식을
가정한다(DuckDNS 등 대부분의 무료 DDNS가 이 방식).

```bash
sudo mkdir -p /opt/rtic-infra /etc/rtic-ddns
sudo cp -r ddns /opt/rtic-infra/
echo 'DDNS_UPDATE_URL="https://www.duckdns.org/update?domains=<도메인>&token=<토큰>&ip="' | sudo tee /etc/rtic-ddns/env
sudo cp systemd/rtic-ddns.service systemd/rtic-ddns.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now rtic-ddns.timer
```

로컬 mock HTTP 서버로 스크립트가 실제 요청을 보내고 성공/실패를 올바른
종료 코드로 구분함을 확인했다(환경변수 누락, 응답 실패 모두 0이 아닌
종료 코드).

## 4. TLS + 리버스 프록시 — `caddy/Caddyfile` (자동)

Caddy는 도메인이 있고 80/443이 열려 있으면 **Let's Encrypt 인증서 발급·갱신을
전부 자동 처리**한다 — certbot 등 별도 도구가 필요 없다.

```bash
sudo apt install -y caddy   # https://caddyserver.com/docs/install#debian-ubuntu-raspbian
sudo mkdir -p /etc/caddy
sudo cp caddy/Caddyfile /etc/caddy/Caddyfile
echo -e 'RTIC_API_DOMAIN=<CI4 API 도메인>\nRTIC_LIVEKIT_DOMAIN=<LiveKit 도메인>' | sudo tee /etc/caddy/rtic.env
# /etc/systemd/system/caddy.service.d/override.conf 에 EnvironmentFile=/etc/caddy/rtic.env 추가 후:
sudo systemctl daemon-reload
sudo systemctl restart caddy
```

`caddy validate`로 설정 문법을 확인했고, 실제 Caddy 프로세스를 로컬에서
구동해 더미 백엔드 2개(CI4 API 역할, LiveKit 역할)를 도메인별로 정확히
라우팅하는지 실제 HTTP 요청으로 검증했다(Host 헤더 기반 라우팅 정상
동작). 실제 Let's Encrypt 인증서 발급 자체는 공인 도메인·인터넷 접근이
필요해 이 환경에서는 검증할 수 없다 — 실배포 시 `sudo systemctl status
caddy`와 `journalctl -u caddy`로 인증서 발급 로그를 확인한다.

## 5. CI4 API 상주 실행 — `systemd/rtic-api.service` (자동)

CI4 API를 `127.0.0.1:8080`에 내부 바인딩으로 상주시켜 Caddy가 프록시할
수 있게 한다(외부에는 443만 노출, 8080은 열지 않음).

```bash
sudo useradd --system --no-create-home rtic || true
sudo cp systemd/rtic-api.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now rtic-api
```

가족 규모 홈서버 스케일이라 php-fpm 없이 CI4 내장 서버(`php spark
serve`)로 충분하다고 판단했다 — 트래픽이 늘면 php-fpm + Caddy
`php_fastcgi`로 교체를 검토한다.
