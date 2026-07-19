"""데몬 프로세스를 실제로 기동해 시그널·종료 코드 동작을 검증한다.

실제 livekitwebrtcsrc(gst-plugins-rs, 소스 빌드 필요)는 이 테스트 환경에 없으므로
`RTIC_TEST_SOURCE_DESCRIPTION`(테스트 전용 우회 환경변수)으로 audiotestsrc를
대신 주입한다. 운영 환경에서는 이 변수를 설정하지 않으면 기존과 동일하게
livekitwebrtcsrc를 사용한다.
"""

import os
import signal
import subprocess
import sys
import time

_BASE_ENV = {
    "RTIC_LIVEKIT_WS_URL": "ws://localhost:7880",
    "RTIC_LIVEKIT_API_KEY": "test-key",
    "RTIC_LIVEKIT_API_SECRET": "test-secret",
    "RTIC_LIVEKIT_ROOM_NAME": "rtic-home",
    "RTIC_AUDIO_SINK": "fakesink",
}


def _spawn(
    source_description: str, metrics_port: int, extra_env: dict | None = None
) -> subprocess.Popen:
    env = dict(os.environ)
    env.update(_BASE_ENV)
    env["RTIC_TEST_SOURCE_DESCRIPTION"] = source_description
    env["RTIC_METRICS_PORT"] = str(metrics_port)
    if extra_env:
        env.update(extra_env)

    return subprocess.Popen(
        [sys.executable, "-m", "rtic_daemon"],
        env=env,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
    )


def test_sigterm_causes_clean_exit_zero():
    proc = _spawn("audiotestsrc is-live=true wave=silence", metrics_port=9581)
    try:
        time.sleep(1.0)  # 파이프라인이 PLAYING까지 올라올 시간을 준다
        proc.send_signal(signal.SIGTERM)
        returncode = proc.wait(timeout=10)
    finally:
        if proc.poll() is None:
            proc.kill()
            proc.wait()

    assert returncode == 0, proc.stdout.read()


def test_stream_end_causes_exit_one():
    # num-buffers로 유한한 스트림을 만들어 자연스럽게 EOS가 발생하게 한다
    # (재접속 대상 실패로 취급 — systemd가 지수 백오프로 재시작해야 하는 경로).
    proc = _spawn("audiotestsrc is-live=true wave=silence num-buffers=10", metrics_port=9582)
    try:
        returncode = proc.wait(timeout=15)
    finally:
        if proc.poll() is None:
            proc.kill()
            proc.wait()

    assert returncode == 1, proc.stdout.read()


def test_metrics_endpoint_serves_prometheus_format():
    import urllib.request

    proc = _spawn("audiotestsrc is-live=true wave=silence", metrics_port=9583)
    try:
        time.sleep(1.0)
        with urllib.request.urlopen("http://localhost:9583/metrics", timeout=5) as response:
            body = response.read().decode()

        assert "rtic_daemon_up 1.0" in body
        assert "rtic_daemon_pipeline_state" in body
    finally:
        proc.send_signal(signal.SIGTERM)
        try:
            proc.wait(timeout=10)
        except subprocess.TimeoutExpired:
            proc.kill()
            proc.wait()
