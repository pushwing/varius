import gi

gi.require_version("Gst", "1.0")
from gi.repository import Gst  # noqa: E402

from rtic_daemon.config import DaemonConfig  # noqa: E402
from rtic_daemon.pipeline import (  # noqa: E402
    build_pipeline,
    build_pipeline_description,
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
