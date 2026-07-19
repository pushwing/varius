# rtic 리눅스 수신 데몬

자택 우분투 서버에서 LiveKit 룸에 참가자로 접속해 오디오를 디코딩한 뒤 물리
스피커로 출력하는 데몬입니다. Python + GStreamer(PyGObject)로 구현했으며,
재접속·헬스체크는 애플리케이션이 아니라 **systemd(지수 백오프 재시작)** 와
**Prometheus `/metrics`** 로 위임합니다.

## 구성

- `rtic_daemon/config.py` — 환경변수 기반 설정(`DaemonConfig`)
- `rtic_daemon/pipeline.py` — GStreamer 파이프라인 조립 (`livekitwebrtcsrc ! queue ! audioconvert ! audioresample ! <sink>`)
- `rtic_daemon/daemon.py` — 파이프라인 실행 루프, GLib 메인루프, 버스 메시지(ERROR/EOS) 처리
- `rtic_daemon/health.py` — Prometheus 메트릭(`rtic_daemon_up`, `rtic_daemon_pipeline_state`, `rtic_daemon_start_time_seconds`)
- `systemd/rtic-daemon.service` — systemd 유닛(지수 백오프 재시작)

## 동작 원리 — "재접속"을 프로세스 재시작으로 위임

데몬은 자체적으로 재연결 루프를 구현하지 않습니다. GStreamer 파이프라인이
에러(`ERROR`)나 스트림 종료(`EOS`, 예: LiveKit 연결 끊김)를 만나면 **종료 코드
1로 즉시 종료**하고, systemd가 `RestartSteps`/`RestartMaxDelaySec`(systemd
254+, Ubuntu 24.04+)로 지수 백오프하며 재시작합니다. `SIGTERM`/`SIGINT`로 받은
정상 종료는 종료 코드 0이라 재시작 대상이 아닙니다.

## 사전 준비 (자택 우분투 서버)

```bash
sudo apt install python3 python3-venv python3-gi \
  gstreamer1.0-tools gstreamer1.0-plugins-base gstreamer1.0-plugins-good \
  gstreamer1.0-plugins-bad gstreamer1.0-alsa gstreamer1.0-pulseaudio
```

### livekitwebrtcsrc 설치 (표준 apt 저장소에 없음)

`livekitwebrtcsrc`는 GStreamer 공식 Rust 플러그인(`gst-plugins-rs`)에
포함돼 있지만 `livekit` feature가 기본 빌드에는 꺼져 있어 아래 중 하나가
필요합니다.

- **소스 빌드**: Rust 툴체인 + `cargo-c` 설치 후 `gst-plugins-rs`를
  `--features livekit`로 빌드해 `cargo cinstall`.
- **서드파티 사전빌드 `.deb`**: https://github.com/mopidy/gst-plugins-rs-build/releases 에서
  플랫폼에 맞는 패키지를 받아 설치.

플러그인이 GStreamer 기본 prefix 밖에 설치됐다면 `GST_PLUGIN_PATH`를
`systemd` 유닛의 `Environment=`에 추가해야 한다.

## 설치

```bash
sudo mkdir -p /opt/rtic-daemon
sudo cp -r rtic_daemon pyproject.toml /opt/rtic-daemon/
cd /opt/rtic-daemon
python3 -m venv --system-site-packages .venv   # python3-gi를 상속받기 위해 필수
.venv/bin/pip install -e .

sudo useradd --system --group audio --no-create-home rtic
sudo mkdir -p /etc/rtic-daemon
sudo cp .env.example /etc/rtic-daemon/env   # 실제 값으로 교체
sudo cp systemd/rtic-daemon.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now rtic-daemon
```

## 로컬 개발/테스트 (macOS/Linux 공통)

실제 `livekitwebrtcsrc` 없이 데몬의 프로세스 수명주기(시그널 처리·종료
코드·헬스체크)를 검증할 수 있다. `tests/test_process.py`는
`RTIC_TEST_SOURCE_DESCRIPTION`(테스트 전용 환경변수)으로 `audiotestsrc`를
대신 주입해 실제 서브프로세스를 띄워 검증한다.

```bash
# PyGObject가 설치된 Python(예: Homebrew python3.14, 또는 Ubuntu의 python3)으로 venv 생성
python3 -m venv --system-site-packages .venv
.venv/bin/pip install -e ".[dev]"

.venv/bin/ruff check .
.venv/bin/ruff format --check .
.venv/bin/python -m pytest -v
```

## 헬스체크 (Prometheus)

`RTIC_METRICS_PORT`(기본 9477)에서 `/metrics`를 서빙한다. Prometheus
스크레이프 설정 예:

```yaml
scrape_configs:
  - job_name: rtic-daemon
    static_configs:
      - targets: ["localhost:9477"]
```

프로세스가 죽어 있으면 스크레이프 자체가 실패하므로(`up{job="rtic-daemon"} == 0`)
재시작 여부는 Prometheus의 기본 `up` 메트릭으로도 확인할 수 있다.
`rtic_daemon_pipeline_state`는 GStreamer `Gst.State` 정수값(NULL=1, READY=2,
PAUSED=3, PLAYING=4)이다.
