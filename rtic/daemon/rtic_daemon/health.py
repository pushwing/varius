from __future__ import annotations

import time

from prometheus_client import Gauge, start_http_server

UP = Gauge("rtic_daemon_up", "데몬 프로세스가 기동해 있으면 1")
PIPELINE_STATE = Gauge(
    "rtic_daemon_pipeline_state",
    "GStreamer 파이프라인 상태(Gst.State 정수값: NULL=1, READY=2, PAUSED=3, PLAYING=4)",
)
START_TIME = Gauge("rtic_daemon_start_time_seconds", "데몬 프로세스가 시작된 유닉스 타임스탬프")


def start_metrics_server(port: int) -> None:
    start_http_server(port)
    UP.set(1)
    START_TIME.set(time.time())


def set_pipeline_state(state_value: int) -> None:
    PIPELINE_STATE.set(state_value)
