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


def test_pipeline_error_restarts_in_process_not_exit():
    # num-buffers로 유한한 스트림을 만들어 EOS가 반복 발생하게 한다.
    # 예전에는 EOS -> 프로세스 종료(코드 1) -> systemd 재시작이었으나,
    # 양방향에서는 한 방향 끊김이 다른 방향을 죽이지 않도록 **해당 파이프라인만
    # in-process 백오프 재시작**한다. 따라서 프로세스는 계속 살아 있어야 한다.
    proc = _spawn("audiotestsrc is-live=true wave=silence num-buffers=10", metrics_port=9582)
    try:
        time.sleep(3.0)  # EOS -> 재시작 사이클이 여러 번 돌 시간을 준다
        assert proc.poll() is None, "프로세스가 종료됐습니다(재시작 대신):\n" + proc.stdout.read()
        proc.send_signal(signal.SIGTERM)
        returncode = proc.wait(timeout=10)
    finally:
        if proc.poll() is None:
            proc.kill()
            proc.wait()

    # 정상 종료(SIGTERM)는 항상 0 — 파이프라인 에러는 프로세스 종료 코드에 영향 없음.
    assert returncode == 0, proc.stdout.read()


def test_mic_enabled_runs_both_pipelines_and_exits_clean_on_sigterm():
    # 양방향(마이크 퍼블리시) 활성화 시 수신·송신 두 파이프라인이 함께 떠도
    # SIGTERM으로 깨끗하게 종료(코드 0)돼야 한다. 실제 livekitwebrtcsink는
    # 이 환경에 없으므로 RTIC_TEST_SINK_DESCRIPTION=fakesink로 대체하고,
    # 마이크 소스도 audiotestsrc로 대체한다.
    proc = _spawn(
        "audiotestsrc is-live=true wave=silence",
        metrics_port=9584,
        extra_env={
            "RTIC_MIC_ENABLED": "true",
            "RTIC_AUDIO_SOURCE": "audiotestsrc is-live=true wave=silence",
            "RTIC_TEST_SINK_DESCRIPTION": "fakesink",
        },
    )
    try:
        time.sleep(1.0)
        proc.send_signal(signal.SIGTERM)
        returncode = proc.wait(timeout=10)
        output = proc.stdout.read()
    finally:
        if proc.poll() is None:
            proc.kill()
            proc.wait()

    assert returncode == 0, output
    # 마이크 퍼블리시 파이프라인이 실제로 기동됐는지 로그로 확인한다.
    assert "마이크 퍼블리시" in output, output


def test_speaker_error_keeps_mic_and_process_alive():
    # 수신(스피커) 소스가 유한(EOS 반복)이어도, 마이크 퍼블리시 파이프라인과
    # 프로세스는 살아 있어야 한다(한 방향 끊김이 다른 방향을 죽이지 않음).
    proc = _spawn(
        "audiotestsrc is-live=true wave=silence num-buffers=10",  # 수신측: 곧 EOS
        metrics_port=9585,
        extra_env={
            "RTIC_MIC_ENABLED": "true",
            "RTIC_AUDIO_SOURCE": "audiotestsrc is-live=true wave=silence",  # 마이크측: 무한
            "RTIC_TEST_SINK_DESCRIPTION": "fakesink",
        },
    )
    try:
        time.sleep(3.0)
        alive = proc.poll() is None
        proc.send_signal(signal.SIGTERM)
        returncode = proc.wait(timeout=10)
        output = proc.stdout.read()
    finally:
        if proc.poll() is None:
            proc.kill()
            proc.wait()

    assert alive, "수신측 에러로 프로세스가 죽었습니다:\n" + output
    assert returncode == 0, output
    assert "마이크 퍼블리시" in output, output


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
