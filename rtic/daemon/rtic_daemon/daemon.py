from __future__ import annotations

import logging
import signal

import gi

gi.require_version("Gst", "1.0")
from gi.repository import GLib, Gst  # noqa: E402

from . import health
from .config import DaemonConfig
from .pipeline import build_pipeline

logger = logging.getLogger(__name__)


def run(config: DaemonConfig, source_description: str | None = None) -> int:
    """파이프라인을 PLAYING 상태로 돌리고 종료할 때까지 블록한다.

    반환값은 프로세스 종료 코드로 쓴다: 정상 종료(SIGTERM/SIGINT)는 0,
    파이프라인 에러·EOS(연결 끊김 등)는 1 — systemd가 지수 백오프로
    재시작하도록 실패로 취급한다.
    """
    Gst.init(None)
    health.start_metrics_server(config.metrics_port)

    pipeline = build_pipeline(config, source_description)
    loop = GLib.MainLoop()

    state = {"exit_code": 0, "shutdown_requested": False}

    def _on_unix_signal(signum: int) -> bool:
        state["shutdown_requested"] = True
        logger.info("signal %s 수신 — 파이프라인을 정상 종료합니다", signum)
        loop.quit()
        return GLib.SOURCE_REMOVE

    GLib.unix_signal_add(GLib.PRIORITY_DEFAULT, signal.SIGTERM, _on_unix_signal, signal.SIGTERM)
    GLib.unix_signal_add(GLib.PRIORITY_DEFAULT, signal.SIGINT, _on_unix_signal, signal.SIGINT)

    def _on_bus_message(_bus: Gst.Bus, message: Gst.Message) -> bool:
        if message.type == Gst.MessageType.ERROR:
            err, debug = message.parse_error()
            logger.error("GStreamer 에러: %s (%s)", err, debug)
            state["exit_code"] = 1
            loop.quit()
        elif message.type == Gst.MessageType.EOS:
            logger.warning("GStreamer 스트림 종료(EOS) — 재접속을 위해 종료합니다")
            state["exit_code"] = 1
            loop.quit()
        elif message.type == Gst.MessageType.STATE_CHANGED and message.src == pipeline:
            _old, new, _pending = message.parse_state_changed()
            health.set_pipeline_state(int(new))
        return True

    bus = pipeline.get_bus()
    bus.add_signal_watch()
    bus.connect("message", _on_bus_message)

    pipeline.set_state(Gst.State.PLAYING)
    try:
        loop.run()
    finally:
        pipeline.set_state(Gst.State.NULL)
        health.UP.set(0)

    if state["shutdown_requested"]:
        return 0

    return state["exit_code"]
