import json

import jwt as pyjwt

from rtic_daemon.config import DaemonConfig
from rtic_daemon.status_reporter import RETURN_MESSAGE_TOPIC, StatusReporter, build_status_token


def _config(**overrides):
    defaults = dict(
        ws_url="ws://localhost:7880",
        api_key="test-key",
        api_secret="test-secret-0123456789abcdef-0123456789",
        room_name="rtic-home",
        identity="rtic-speaker",
    )
    defaults.update(overrides)
    return DaemonConfig(**defaults)


def test_build_status_token_has_data_only_grants():
    config = _config()
    token = build_status_token(config, "rtic-speaker-status")

    payload = pyjwt.decode(token, config.api_secret, algorithms=["HS256"])

    assert payload["iss"] == config.api_key
    assert payload["sub"] == "rtic-speaker-status"
    assert payload["video"]["room"] == "rtic-home"
    assert payload["video"]["roomJoin"] is True
    assert payload["video"]["canPublishData"] is True
    assert payload["video"]["canPublish"] is False
    assert payload["video"]["canSubscribe"] is False


def test_status_reporter_identity_is_suffixed_to_avoid_collision():
    reporter = StatusReporter(_config(identity="rtic-speaker"))

    assert reporter._identity == "rtic-speaker-status"


def test_send_queues_json_payload_matching_schema():
    reporter = StatusReporter(_config())

    reporter.send("status", "스피커 연결됨")

    raw = reporter._queue.get_nowait()
    payload = json.loads(raw)

    assert payload["type"] == "status"
    assert payload["message"] == "스피커 연결됨"
    assert isinstance(payload["ts"], int)


def test_return_message_topic_matches_web_app_schema():
    # rtic/web/src/messageSchema.js의 RETURN_MESSAGE_TOPIC과 반드시 일치해야 한다.
    assert RETURN_MESSAGE_TOPIC == "rtic.status"
