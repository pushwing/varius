from __future__ import annotations

import gi

gi.require_version("Gst", "1.0")
from gi.repository import Gst  # noqa: E402

from .config import DaemonConfig


def _quote(value: str) -> str:
    return '"' + value.replace("\\", "\\\\").replace('"', '\\"') + '"'


def livekit_source_description(config: DaemonConfig) -> str:
    """운영 환경에서 사용할 livekitwebrtcsrc 소스 세그먼트를 만든다.

    livekitwebrtcsrc(gst-plugins-rs, `--features livekit`)는 표준 apt 저장소에
    없어 자택 서버에 소스 빌드로 별도 설치해야 한다(README 참고).
    """
    return (
        "livekitwebrtcsrc name=src "
        f"signaller::ws-url={_quote(config.ws_url)} "
        f"signaller::api-key={_quote(config.api_key)} "
        f"signaller::secret-key={_quote(config.api_secret)} "
        f"signaller::room-name={_quote(config.room_name)} "
        f"signaller::identity={_quote(config.identity)} "
        f"signaller::participant-name={_quote(config.identity)}"
    )


def build_pipeline_description(config: DaemonConfig, source_description: str | None = None) -> str:
    """전체 gst-launch 스타일 파이프라인 문자열을 만든다.

    `source_description`을 넘기면(테스트에서 audiotestsrc 등으로 대체) 그 값을
    소스 세그먼트로 사용하고, 생략하면 운영용 livekitwebrtcsrc를 사용한다.
    """
    if source_description is None:
        source_description = livekit_source_description(config)

    return f"{source_description} ! queue ! audioconvert ! audioresample ! {config.audio_sink}"


def build_pipeline(config: DaemonConfig, source_description: str | None = None) -> Gst.Pipeline:
    description = build_pipeline_description(config, source_description)
    pipeline = Gst.parse_launch(description)
    if pipeline is None:
        raise RuntimeError("GStreamer 파이프라인 생성에 실패했습니다.")

    return pipeline
