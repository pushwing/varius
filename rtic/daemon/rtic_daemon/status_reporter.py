from __future__ import annotations

import asyncio
import json
import logging
import queue
import threading
import time

from livekit import api, rtc

from .config import DaemonConfig

logger = logging.getLogger(__name__)

RETURN_MESSAGE_TOPIC = "rtic.status"

_CONNECT_TIMEOUT_SECONDS = 5.0

_STOP = object()


def build_status_token(config: DaemonConfig, identity: str) -> str:
    """데이터 채널 전용(오디오 발행/구독 불가) LiveKit 토큰을 발급한다."""
    grants = api.VideoGrants(
        room_join=True,
        room=config.room_name,
        can_publish=False,
        can_subscribe=False,
        can_publish_data=True,
    )

    return (
        api.AccessToken(config.api_key, config.api_secret)
        .with_identity(identity)
        .with_grants(grants)
        .to_jwt()
    )


class StatusReporter:
    """LiveKit 텍스트 스트림(토픽 `rtic.status`)으로 리턴 메시지를 발신한다.

    오디오 수신은 GStreamer `livekitwebrtcsrc`(daemon.py, GLib 메인루프)가
    담당하고, 이 클래스는 그와 별개로 데이터 채널 전용 참가자로 접속하는
    백그라운드 asyncio 스레드를 둔다 — GLib 루프에 asyncio를 억지로
    통합하지 않아도 되고, 오디오 참가자와 identity 충돌도 피할 수 있다.

    발신 실패는 오디오 경로를 막지 않도록 항상 로깅만 하고 무시한다
    (리턴 메시지는 부가 기능이지 핵심 기능이 아니다).
    """

    def __init__(self, config: DaemonConfig) -> None:
        self._config = config
        self._identity = f"{config.identity}-status"
        self._queue: queue.Queue = queue.Queue()
        self._thread: threading.Thread | None = None

    def start(self) -> None:
        self._thread = threading.Thread(target=self._run, name="status-reporter", daemon=True)
        self._thread.start()

    def send(self, message_type: str, message: str) -> None:
        payload = json.dumps({"type": message_type, "message": message, "ts": int(time.time())})
        self._queue.put(payload)

    def stop(self) -> None:
        self._queue.put(_STOP)
        if self._thread is not None:
            self._thread.join(timeout=_CONNECT_TIMEOUT_SECONDS + 1)

    def _run(self) -> None:
        try:
            asyncio.run(self._main())
        except Exception:
            logger.exception("상태 리포터 스레드가 예기치 않게 종료됐습니다.")

    async def _main(self) -> None:
        room = rtc.Room()
        token = build_status_token(self._config, self._identity)

        try:
            await asyncio.wait_for(
                room.connect(
                    self._config.ws_url,
                    token,
                    options=rtc.RoomOptions(auto_subscribe=False),
                ),
                timeout=_CONNECT_TIMEOUT_SECONDS,
            )
        except Exception:
            logger.exception(
                "상태 리포터가 LiveKit 룸에 연결하지 못했습니다 — 리턴 메시지 발신을 건너뜁니다."
            )
            return

        try:
            loop = asyncio.get_running_loop()
            while True:
                item = await loop.run_in_executor(None, self._queue.get)
                if item is _STOP:
                    break
                try:
                    await room.local_participant.send_text(item, topic=RETURN_MESSAGE_TOPIC)
                except Exception:
                    logger.exception("리턴 메시지 발신에 실패했습니다: %s", item)
        finally:
            await room.disconnect()
