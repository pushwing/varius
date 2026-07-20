import gi

gi.require_version("Gst", "1.0")
from gi.repository import Gst  # noqa: E402

from rtic_daemon.config import DaemonConfig  # noqa: E402
from rtic_daemon.pipeline import (  # noqa: E402
    build_pipeline,
    build_pipeline_description,
    build_publish_pipeline,
    build_publish_pipeline_description,
    livekit_sink_description,
    livekit_source_description,
)

Gst.init(None)


def _config(**overrides):
    defaults = dict(
        ws_url="ws://localhost:7880",
        api_key="test-key",
        api_secret="test-secret",
        room_name="rtic-home",
    )
    defaults.update(overrides)
    return DaemonConfig(**defaults)


def test_livekit_source_description_includes_all_signaller_properties():
    description = livekit_source_description(_config(identity="living-room"))

    assert "livekitwebrtcsrc" in description
    assert 'signaller::ws-url="ws://localhost:7880"' in description
    assert 'signaller::api-key="test-key"' in description
    assert 'signaller::secret-key="test-secret"' in description
    assert 'signaller::room-name="rtic-home"' in description
    assert 'signaller::identity="living-room"' in description


def test_build_pipeline_description_appends_audio_chain():
    description = build_pipeline_description(
        _config(audio_sink="fakesink"), source_description="audiotestsrc"
    )

    assert description == "audiotestsrc ! queue ! audioconvert ! audioresample ! fakesink"


def test_build_pipeline_creates_real_gst_pipeline_with_stub_source():
    # 실제 livekitwebrtcsrc(gst-plugins-rs, 소스 빌드 필요)는 이 테스트 환경에
    # 설치돼 있지 않으므로 audiotestsrc로 대체해 파이프라인 조립 로직만 검증한다.
    pipeline = build_pipeline(
        _config(audio_sink="fakesink"),
        source_description="audiotestsrc is-live=true wave=silence",
    )

    assert isinstance(pipeline, Gst.Pipeline)

    change = pipeline.set_state(Gst.State.READY)
    assert change != Gst.StateChangeReturn.FAILURE

    pipeline.set_state(Gst.State.NULL)


# --- 역방향(퍼블리시): 마이크 캡처 -> LiveKit 송신 ---


def test_livekit_sink_description_includes_all_signaller_properties():
    description = livekit_sink_description(_config(identity="living-room"))

    assert "livekitwebrtcsink" in description
    assert 'signaller::ws-url="ws://localhost:7880"' in description
    assert 'signaller::api-key="test-key"' in description
    assert 'signaller::secret-key="test-secret"' in description
    assert 'signaller::room-name="rtic-home"' in description


def test_livekit_sink_uses_separate_mic_identity():
    # 마이크 퍼블리셔는 오디오 수신기(identity)와 identity 충돌을 피하려고
    # `<identity>-mic` 별도 참가자로 접속한다(status_reporter의 `-status` 선례).
    description = livekit_sink_description(_config(identity="living-room"))

    assert 'signaller::identity="living-room-mic"' in description
    assert 'signaller::participant-name="living-room-mic"' in description


def test_build_publish_pipeline_description_prepends_audio_source_chain():
    # livekitwebrtcsink가 내부적으로 Opus 인코딩을 처리하므로 원시 오디오를
    # 그대로 주입한다(별도 opusenc 없음).
    description = build_publish_pipeline_description(
        _config(audio_source="audiotestsrc"), sink_description="fakesink"
    )

    assert description == "audiotestsrc ! queue ! audioconvert ! audioresample ! fakesink"


def test_build_publish_pipeline_creates_real_gst_pipeline_with_stub_sink():
    # 실제 livekitwebrtcsink(gst-plugins-rs, 소스 빌드 필요)는 이 테스트 환경에
    # 없으므로 fakesink로 대체해 파이프라인 조립 로직만 검증한다.
    pipeline = build_publish_pipeline(
        _config(audio_source="audiotestsrc is-live=true wave=silence"),
        sink_description="fakesink",
    )

    assert isinstance(pipeline, Gst.Pipeline)

    change = pipeline.set_state(Gst.State.READY)
    assert change != Gst.StateChangeReturn.FAILURE

    pipeline.set_state(Gst.State.NULL)
