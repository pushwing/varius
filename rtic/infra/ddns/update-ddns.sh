#!/usr/bin/env bash
# 고정 공인 IP가 아닐 경우, DDNS 제공자에 현재 공인 IP를 주기적으로 갱신한다.
#
# 특정 DDNS 서비스에 종속되지 않도록 "업데이트 URL 템플릿" 방식을 쓴다.
# 대부분의 무료 DDNS(DuckDNS, No-IP 등)는 GET 요청 한 번으로 갱신되는
# URL을 제공하므로, 그 URL을 DDNS_UPDATE_URL로 넣으면 된다.
#
# 예 (DuckDNS): DDNS_UPDATE_URL="https://www.duckdns.org/update?domains=${DDNS_DOMAIN}&token=${DDNS_TOKEN}&ip="
#
# 필수 환경변수(EnvironmentFile로 주입): DDNS_UPDATE_URL
# systemd 타이머로 주기 실행한다(rtic-ddns.timer).
set -euo pipefail

if [[ -z "${DDNS_UPDATE_URL:-}" ]]; then
  echo "DDNS_UPDATE_URL 환경변수가 설정되지 않았습니다." >&2
  exit 1
fi

response="$(curl -fsS --max-time 10 "${DDNS_UPDATE_URL}")"
echo "DDNS 갱신 응답: ${response}"
