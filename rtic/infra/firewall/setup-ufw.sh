#!/usr/bin/env bash
# rtic 온프레미스 서버 방화벽(ufw) 설정.
#
# 외부에 직접 노출하는 포트만 연다 — CI4 API/LiveKit 시그널링은 Caddy가
# 443에서만 받아 내부(127.0.0.1)로 리버스 프록시하므로 7880/7881/8080은
# 열지 않는다(공격 표면 최소화).
#
# 사용법: sudo bash setup-ufw.sh
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "root 권한으로 실행해야 합니다: sudo bash $0" >&2
  exit 1
fi

if ! command -v ufw >/dev/null 2>&1; then
  echo "ufw가 설치돼 있지 않습니다: apt install ufw" >&2
  exit 1
fi

# SSH를 막아 원격 접속이 끊기는 사고를 방지하기 위해 가장 먼저 허용한다.
ufw allow 22/tcp comment "SSH"

# Let's Encrypt HTTP-01 challenge + HTTP->HTTPS 리다이렉트용.
ufw allow 80/tcp comment "Caddy ACME HTTP-01"

# CI4 API + LiveKit 시그널링(Caddy 리버스 프록시, 자동 HTTPS).
ufw allow 443/tcp comment "Caddy HTTPS (CI4 API, LiveKit signaling)"

# LiveKit 내장 TURN.
ufw allow 3478/udp comment "LiveKit embedded TURN"

# LiveKit ICE 미디어 릴레이 범위 (rtic/livekit/livekit.yaml의 rtc.port_range와 일치해야 함).
ufw allow 50000:50100/udp comment "LiveKit ICE media range"

ufw --force enable
ufw status verbose
