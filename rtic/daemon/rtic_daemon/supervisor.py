from __future__ import annotations

import logging
from collections.abc import Callable

import gi

gi.require_version("Gst", "1.0")
from gi.repository import GLib, Gst  # noqa: E402

logger = logging.getLogger(__name__)

# 파이프라인 재시작 지수 백오프(초). 첫 실패는 곧바로(1초) 재시도하고,
# 반복 실패 시 30초까지 늘려 tight loop을 막는다.
_BACKOFF_BASE_SECONDS = 1
_BACKOFF_MAX_SECONDS = 30


class SupervisedPipeline:
    """GStreamer 파이프라인 하나를 감독하며 에러/EOS 시 **그 파이프라인만**
    지수 백오프로 in-process 재구성한다.

    양방향 인터콤에서 수신(스피커)·송신(마이크)은 서로 독립이어야 한다 —
    한 방향의 네트워크 끊김(예: `livekitwebrtcsrc`의 nicesrc가 앱 송신 종료 시
    올리는 "Internal data stream error")이 다른 방향이나 프로세스 전체를
    죽이면 안 된다. 그래서 프로세스는 SIGTERM/SIGINT로만 종료하고, 파이프라인
    에러는 여기서 해당 파이프라인만 다시 세운다.
    """

    def __init__(
        self,
        role: str,
        build_fn: Callable[[], Gst.Pipeline],
        *,
        on_playing: Callable[[], None] | None = None,
        on_built: Callable[[Gst.Pipeline], None] | None = None,
        on_state: Callable[[int], None] | None = None,
        backoff_base: int = _BACKOFF_BASE_SECONDS,
        backoff_max: int = _BACKOFF_MAX_SECONDS,
    ) -> None:
        self.role = role
        self._build_fn = build_fn
        self._on_playing = on_playing  # 최초 PLAYING 시 1회 호출(상태 발신용)
        self._on_built = on_built  # 새 파이프라인 생성 직후(pad-added 연결 등)
        self._on_state = on_state  # STATE_CHANGED 시 호출(헬스 메트릭 등)
        self._backoff_base = backoff_base
        self._backoff_max = backoff_max
        self._backoff = backoff_base
        self._pipeline: Gst.Pipeline | None = None
        self._restart_source_id: int | None = None
        self._played_once = False
        self._stopped = False

    @property
    def pipeline(self) -> Gst.Pipeline | None:
        return self._pipeline

    def start(self) -> None:
        """최초 기동. 빌드 실패도 프로세스를 죽이지 않고 백오프 재시도한다."""
        self._try_build_and_play()

    def stop(self) -> None:
        self._stopped = True
        if self._restart_source_id is not None:
            GLib.source_remove(self._restart_source_id)
            self._restart_source_id = None
        if self._pipeline is not None:
            self._pipeline.set_state(Gst.State.NULL)

    def _try_build_and_play(self) -> None:
        try:
            self._build_and_play()
        except Exception:
            logger.exception("[%s] 파이프라인 구성 실패 — 백오프 후 재시도합니다", self.role)
            self._schedule_restart()

    def _build_and_play(self) -> None:
        pipeline = self._build_fn()
        self._pipeline = pipeline

        bus = pipeline.get_bus()
        bus.add_signal_watch()
        bus.connect("message", self._on_message)

        if self._on_built is not None:
            self._on_built(pipeline)

        change = pipeline.set_state(Gst.State.PLAYING)
        logger.info("[%s] 파이프라인을 PLAYING으로 전환 요청 — 결과: %s", self.role, change)

    def _on_message(self, _bus: Gst.Bus, message: Gst.Message) -> bool:
        if self._stopped:
            return True

        if message.type == Gst.MessageType.ERROR:
            err, debug = message.parse_error()
            logger.error(
                "[%s] GStreamer 에러: %s (%s) — 해당 파이프라인만 재시작합니다",
                self.role,
                err,
                debug,
            )
            self._schedule_restart()
        elif message.type == Gst.MessageType.EOS:
            logger.warning("[%s] 스트림 종료(EOS) — 해당 파이프라인만 재시작합니다", self.role)
            self._schedule_restart()
        elif message.type == Gst.MessageType.STATE_CHANGED and message.src == self._pipeline:
            old, new, _pending = message.parse_state_changed()
            logger.info("[%s] 파이프라인 상태 전환: %s -> %s", self.role, old, new)
            if self._on_state is not None:
                self._on_state(int(new))
            if new == Gst.State.PLAYING:
                # 성공적으로 재생 시작 -> 백오프 리셋.
                self._backoff = self._backoff_base
                if not self._played_once:
                    self._played_once = True
                    if self._on_playing is not None:
                        self._on_playing()
        return True

    def _schedule_restart(self) -> None:
        # 이미 재시작 예약돼 있으면(연속 에러 메시지 등) 중복 예약하지 않는다.
        if self._stopped or self._restart_source_id is not None:
            return
        if self._pipeline is not None:
            self._pipeline.set_state(Gst.State.NULL)

        delay = self._backoff
        self._backoff = min(self._backoff * 2, self._backoff_max)
        logger.info("[%s] %d초 후 파이프라인을 재구성합니다", self.role, delay)
        self._restart_source_id = GLib.timeout_add_seconds(delay, self._restart)

    def _restart(self) -> bool:
        self._restart_source_id = None
        if not self._stopped:
            self._try_build_and_play()
        return GLib.SOURCE_REMOVE
