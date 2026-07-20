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
from .supervisor import SupervisedPipeline

logger = logging.getLogger(__name__)


def run(
    config: DaemonConfig,
    source_description: str | None = None,
    sink_description: str | None = None,
) -> int:
    """파이프라인을 PLAYING 상태로 돌리고 종료할 때까지 블록한다.

    반환값은 프로세스 종료 코드로 쓴다. 프로세스는 **SIGTERM/SIGINT로만 종료**
    (코드 0)한다. 개별 파이프라인의 에러·EOS는 프로세스를 죽이지 않고
    `SupervisedPipeline`이 그 파이프라인만 지수 백오프로 in-process 재구성한다
    — 양방향(수신·송신)에서 한 방향의 끊김이 다른 방향을 죽이지 않게 하기
    위함이다. (systemd `Restart=on-failure`는 프로세스 자체가 크래시한 경우의
    안전망으로만 남는다.)

    `config.mic_enabled`가 켜져 있으면 수신(스피커) 파이프라인과 함께
    송신(마이크 퍼블리시) 파이프라인도 같은 GLib 메인루프에서 감독한다.
    """
    Gst.init(None)
    health.start_metrics_server(config.metrics_port)

    reporter = StatusReporter(config)
    loop = GLib.MainLoop()

    def _on_pad_added(_element: Gst.Element, pad: Gst.Pad) -> None:
        logger.info(
            "원격 트랙 수신 시작: pad=%s caps=%s",
            pad.get_name(),
            pad.query_caps(None).to_string(),
        )

    def _wire_receive_src(pipeline: Gst.Pipeline) -> None:
        src = pipeline.get_by_name("src")
        if src is not None:
            src.connect("pad-added", _on_pad_added)

    supervisors: list[SupervisedPipeline] = [
        SupervisedPipeline(
            "speaker",
            lambda: build_pipeline(config, source_description),
            on_built=_wire_receive_src,
            on_playing=lambda: reporter.send("status", "스피커 연결됨"),
            on_state=health.set_pipeline_state,
        )
    ]

    if config.mic_enabled:
        logger.info("마이크 퍼블리시 파이프라인을 구성합니다(양방향 인터콤).")
        supervisors.append(
            SupervisedPipeline(
                "mic",
                lambda: build_publish_pipeline(config, sink_description),
                on_playing=lambda: reporter.send("status", "마이크 연결됨"),
            )
        )

    def _on_unix_signal(signum: int) -> bool:
        logger.info("signal %s 수신 — 파이프라인을 정상 종료합니다", signum)
        loop.quit()
        return GLib.SOURCE_REMOVE

    GLib.unix_signal_add(GLib.PRIORITY_DEFAULT, signal.SIGTERM, _on_unix_signal, signal.SIGTERM)
    GLib.unix_signal_add(GLib.PRIORITY_DEFAULT, signal.SIGINT, _on_unix_signal, signal.SIGINT)

    for supervisor in supervisors:
        supervisor.start()

    reporter.start()

    try:
        loop.run()
    finally:
        for supervisor in supervisors:
            supervisor.stop()
        health.UP.set(0)
        reporter.stop()

    return 0
