import pytest

from rtic_daemon.config import DaemonConfig
from rtic_daemon.errors import ConfigError


def _valid_env(**overrides):
    env = {
        "RTIC_LIVEKIT_WS_URL": "ws://localhost:7880",
        "RTIC_LIVEKIT_API_KEY": "test-key",
        "RTIC_LIVEKIT_API_SECRET": "test-secret",
        "RTIC_LIVEKIT_ROOM_NAME": "rtic-home",
    }
    env.update(overrides)
    return env


def test_from_env_reads_required_fields():
    config = DaemonConfig.from_env(_valid_env())

    assert config.ws_url == "ws://localhost:7880"
    assert config.api_key == "test-key"
    assert config.api_secret == "test-secret"
    assert config.room_name == "rtic-home"


def test_from_env_applies_defaults():
    config = DaemonConfig.from_env(_valid_env())

    assert config.identity == "rtic-speaker"
    # WebRTC 실시간 오디오는 sync=false가 필수라 기본 sink에 포함돼 있어야 한다.
    assert config.audio_sink == "autoaudiosink sync=false"
    assert config.metrics_port == 9477


def test_from_env_allows_overriding_defaults():
    config = DaemonConfig.from_env(
        _valid_env(
            RTIC_DAEMON_IDENTITY="living-room",
            RTIC_AUDIO_SINK="alsasink",
            RTIC_METRICS_PORT="9999",
        )
    )

    assert config.identity == "living-room"
    assert config.audio_sink == "alsasink"
    assert config.metrics_port == 9999


@pytest.mark.parametrize(
    "missing_key",
    [
        "RTIC_LIVEKIT_WS_URL",
        "RTIC_LIVEKIT_API_KEY",
        "RTIC_LIVEKIT_API_SECRET",
        "RTIC_LIVEKIT_ROOM_NAME",
    ],
)
def test_from_env_raises_on_missing_required_field(missing_key):
    env = _valid_env()
    del env[missing_key]

    with pytest.raises(ConfigError, match=missing_key):
        DaemonConfig.from_env(env)


def test_from_env_raises_on_non_integer_metrics_port():
    with pytest.raises(ConfigError, match="RTIC_METRICS_PORT"):
        DaemonConfig.from_env(_valid_env(RTIC_METRICS_PORT="not-a-number"))
