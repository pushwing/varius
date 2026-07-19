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


@dataclass(frozen=True)
class DaemonConfig:
    ws_url: str
    api_key: str
    api_secret: str
    room_name: str
    identity: str = "rtic-speaker"
    audio_sink: str = "autoaudiosink"
    metrics_port: int = 9477

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
            audio_sink=env.get("RTIC_AUDIO_SINK", "autoaudiosink"),
            metrics_port=metrics_port,
        )
