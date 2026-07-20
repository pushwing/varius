from __future__ import annotations

from collections.abc import Mapping
from dataclasses import dataclass

from .errors import ConfigError

_REQUIRED = (
    "RTIC_LIVEKIT_WS_URL",
    "RTIC_LIVEKIT_API_KEY",
    "RTIC_LIVEKIT_API_SECRET",
    "RTIC_LIVEKIT_ROOM_NAME",
)

_TRUTHY = frozenset({"1", "true", "yes", "on"})


def _parse_bool(value: str) -> bool:
    return value.strip().lower() in _TRUTHY


@dataclass(frozen=True)
class DaemonConfig:
    ws_url: str
    api_key: str
    api_secret: str
    room_name: str
    identity: str = "rtic-speaker"
    # WebRTC 실시간 오디오는 sync=true면 클럭 불일치로 소리가 안 난다 —
    # 기본 sink에 sync=false를 반드시 포함한다. 실서버에서는 보통
    # `alsasink device=plughw:N,M sync=false`로 출력 장치까지 지정해
    # override 한다(.env.example 참고).
    audio_sink: str = "autoaudiosink sync=false"
    metrics_port: int = 9477
    # 양방향(역방향) 인터콤: 자택 마이크 오디오를 LiveKit에 퍼블리시할지 여부.
    # 기본 off — 마이크 미연결·스피커 전용 배포에는 영향이 없어야 한다.
    mic_enabled: bool = False
    # 마이크 캡처용 GStreamer 소스 element. 기본은 시스템 기본 입력을 잡는
    # autoaudiosrc이며, 실서버에서는 `arecord -l`로 확인한 캡처 카드를
    # `alsasrc device=plughw:N,M`으로 지정해 override 한다(.env.example 참고).
    audio_source: str = "autoaudiosrc"

    @classmethod
    def from_env(cls, env: Mapping[str, str]) -> DaemonConfig:
        missing = [key for key in _REQUIRED if not env.get(key)]
        if missing:
            raise ConfigError(f"필수 환경변수가 설정되지 않았습니다: {', '.join(missing)}")

        metrics_port_raw = env.get("RTIC_METRICS_PORT", "9477")
        try:
            metrics_port = int(metrics_port_raw)
        except ValueError as exc:
            raise ConfigError(f"RTIC_METRICS_PORT은 정수여야 합니다: {metrics_port_raw!r}") from exc

        return cls(
            ws_url=env["RTIC_LIVEKIT_WS_URL"],
            api_key=env["RTIC_LIVEKIT_API_KEY"],
            api_secret=env["RTIC_LIVEKIT_API_SECRET"],
            room_name=env["RTIC_LIVEKIT_ROOM_NAME"],
            identity=env.get("RTIC_DAEMON_IDENTITY", "rtic-speaker"),
            audio_sink=env.get("RTIC_AUDIO_SINK", "autoaudiosink sync=false"),
            metrics_port=metrics_port,
            mic_enabled=_parse_bool(env.get("RTIC_MIC_ENABLED", "")),
            audio_source=env.get("RTIC_AUDIO_SOURCE", "autoaudiosrc"),
        )
