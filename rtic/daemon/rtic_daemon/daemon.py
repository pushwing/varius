from __future__ import annotations

import logging
import signal

import gi

gi.require_version("Gst", "1.0")
from gi.repository import GLib, Gst  # noqa: E402

from . import health
from .config import DaemonConfig
from .pipeline import build_pipeline, build_publish_pipeline
from .status_reporter import StatusReporter

logger = logging.getLogger(__name__)


def run(
    config: DaemonConfig,
    source_description: str | None = None,
    sink_description: str | None = None,
) -> int:
    """파이프라인을 PLAYING 상태로 돌리고 종료할 때까지 블록한다.

    반환값은 프로세스 종료 코드로 쓴다: 정상 종료(SIGTERM/SIGINT)는 0,
    파이프라인 에러·EOS(연결 끊김 등)는 1 — systemd가 지수 백오프로
    재시작하도록 실패로 취급한다.

    `config.mic_enabled`가 켜져 있으면 수신(스피커) 파이프라인과 함께
    송신(마이크 퍼블리시) 파이프라인도 같은 GLib 메인루프에서 돌린다.
    두 방향 중 어느 쪽이든 에러·EOS면 프로세스를 실패 종료해 systemd가
    전체를 재시작하도록 위임한다(양방향 모두 6절 재접속 정책과 일관).
    """
    Gst.init(None)
    health.start_metrics_server(config.metrics_port)

    reporter = StatusReporter(config)

    # (파이프라인, 역할 라벨) 목록. 수신은 항상, 송신은 mic_enabled일 때만.
    receive_pipeline = build_pipeline(config, source_description)
    pipelines: list[tuple[Gst.Pipeline, str]] = [(receive_pipeline, "speaker")]

    publish_pipeline: Gst.Pipeline | None = None
    if config.mic_enabled:
        logger.info("마이크 퍼블리시 파이프라인을 구성합니다(양방향 인터콤).")
        publish_pipeline = build_publish_pipeline(config, sink_description)
        pipelines.append((publish_pipeline, "mic"))

    loop = GLib.MainLoop()

    state = {
        "exit_code": 0,
        "shutdown_requested": False,
        "speaker_reported": False,
        "mic_reported": False,
    }

    def _on_unix_signal(signum: int) -> bool:
        state["shutdown_requested"] = True
        logger.info("signal %s 수신 — 파이프라인을 정상 종료합니다", signum)
        loop.quit()
        return GLib.SOURCE_REMOVE

    GLib.unix_signal_add(GLib.PRIORITY_DEFAULT, signal.SIGTERM, _on_unix_signal, signal.SIGTERM)
    GLib.unix_signal_add(GLib.PRIORITY_DEFAULT, signal.SIGINT, _on_unix_signal, signal.SIGINT)

    def _make_bus_handler(pipeline: Gst.Pipeline, role: str):
        def _on_bus_message(_bus: Gst.Bus, message: Gst.Message) -> bool:
            if message.type == Gst.MessageType.ERROR:
                err, debug = message.parse_error()
                logger.error("[%s] GStreamer 에러: %s (%s)", role, err, debug)
                if role == "mic":
                    reporter.send("error", f"마이크 파이프라인 에러: {err}")
                else:
                    reporter.send("error", f"오디오 파이프라인 에러: {err}")
                state["exit_code"] = 1
                loop.quit()
            elif message.type == Gst.MessageType.EOS:
                logger.warning("[%s] GStreamer 스트림 종료(EOS) — 재접속을 위해 종료합니다", role)
                reporter.send("status", "연결이 끊어졌습니다 — 재접속을 시도합니다.")
                state["exit_code"] = 1
                loop.quit()
            elif message.type == Gst.MessageType.STATE_CHANGED and message.src == pipeline:
                old, new, _pending = message.parse_state_changed()
                logger.info("[%s] 파이프라인 상태 전환: %s -> %s", role, old, new)
                if role == "speaker":
                    health.set_pipeline_state(int(new))
                    if new == Gst.State.PLAYING and not state["speaker_reported"]:
                        state["speaker_reported"] = True
                        reporter.send("status", "스피커 연결됨")
                elif role == "mic":
                    if new == Gst.State.PLAYING and not state["mic_reported"]:
                        state["mic_reported"] = True
                        reporter.send("status", "마이크 연결됨")
            return True

        return _on_bus_message

    for pipeline, role in pipelines:
        bus = pipeline.get_bus()
        bus.add_signal_watch()
        bus.connect("message", _make_bus_handler(pipeline, role))

    src = receive_pipeline.get_by_name("src")
    if src is not None:

        def _on_pad_added(_element: Gst.Element, pad: Gst.Pad) -> None:
            logger.info(
                "원격 트랙 수신 시작: pad=%s caps=%s",
                pad.get_name(),
                pad.query_caps(None).to_string(),
            )

        src.connect("pad-added", _on_pad_added)

    for pipeline, role in pipelines:
        change = pipeline.set_state(Gst.State.PLAYING)
        logger.info("[%s] 파이프라인을 PLAYING으로 전환 요청 — 결과: %s", role, change)

    reporter.start()

    try:
        loop.run()
    finally:
        for pipeline, _role in pipelines:
            pipeline.set_state(Gst.State.NULL)
        health.UP.set(0)
        reporter.stop()

    if state["shutdown_requested"]:
        return 0

    return state["exit_code"]
