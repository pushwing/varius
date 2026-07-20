# rtic 리눅스 수신 데몬

자택 우분투 서버에서 LiveKit 룸에 참가자로 접속해 오디오를 디코딩한 뒤 물리
스피커로 출력하는 데몬입니다. Python + GStreamer(PyGObject)로 구현했으며,
재접속·헬스체크는 애플리케이션이 아니라 **systemd(지수 백오프 재시작)** 와
**Prometheus `/metrics`** 로 위임합니다.

## 구성

- `rtic_daemon/config.py` — 환경변수 기반 설정(`DaemonConfig`). 양방향(마이크 퍼블리시)은 `RTIC_MIC_ENABLED`/`RTIC_AUDIO_SOURCE`로 제어.
- `rtic_daemon/pipeline.py` — GStreamer 파이프라인 조립. 수신(스피커) `livekitwebrtcsrc ! queue ! audioconvert ! audioresample ! <sink>` + (양방향 시) 송신(마이크) `<source> ! queue ! audioconvert ! audioresample ! livekitwebrtcsink`. 마이크 퍼블리셔는 별개 참가자(`<identity>-mic`)로 접속하고 Opus 인코딩은 sink가 내부 처리한다.
- `rtic_daemon/daemon.py` — 파이프라인 실행 루프, GLib 메인루프, 버스 메시지(ERROR/EOS) 처리. `RTIC_MIC_ENABLED` 시 수신·송신 두 파이프라인을 같은 루프에서 돌리며, 어느 쪽이든 에러·EOS면 실패 종료(systemd 재시작).
- `rtic_daemon/health.py` — Prometheus 메트릭(`rtic_daemon_up`, `rtic_daemon_pipeline_state`, `rtic_daemon_start_time_seconds`)
- `rtic_daemon/status_reporter.py` — LiveKit 텍스트 스트림(토픽 `rtic.status`)으로 앱(`../web/`)에 상태·에러 메시지를 발신. GStreamer/GLib 메인루프와는 별개의 백그라운드 asyncio 스레드에서 데이터 채널 전용 참가자(`<identity>-status`)로 접속한다.
- `systemd/rtic-daemon.service` — systemd 유닛(지수 백오프 재시작)

## 동작 원리 — "재접속"을 프로세스 재시작으로 위임

데몬은 자체적으로 재연결 루프를 구현하지 않습니다. GStreamer 파이프라인이
에러(`ERROR`)나 스트림 종료(`EOS`, 예: LiveKit 연결 끊김)를 만나면 **종료 코드
1로 즉시 종료**하고, systemd가 `RestartSteps`/`RestartMaxDelaySec`(systemd
254+, Ubuntu 24.04+)로 지수 백오프하며 재시작합니다. `SIGTERM`/`SIGINT`로 받은
정상 종료는 종료 코드 0이라 재시작 대상이 아닙니다.

## 사전 준비 (자택 우분투 서버)

> 아래 절차는 Ubuntu 24.04에 **실제 배포하며 검증**한 것이다.

```bash
sudo apt install python3 python3-venv python3-gi \
  gstreamer1.0-tools gstreamer1.0-plugins-base gstreamer1.0-plugins-good \
  gstreamer1.0-plugins-bad gstreamer1.0-alsa gstreamer1.0-pulseaudio \
  gstreamer1.0-nice
```

⚠️ `gstreamer1.0-nice`(libnice, ICE 협상용 `nicesrc`)를 빠뜨리면
`livekitwebrtcsrc`가 파이프라인 구성 중 "missing plugin" 에러로 실패한다.

### 오디오 그룹 (필수)

데몬을 실행하는 사용자가 `audio` 그룹에 있어야 `/dev/snd/*`에 접근할 수
있다. 없으면 `aplay -l`이 "no soundcards found"로 나오고 소리가 안 난다.

```bash
sudo usermod -aG audio "$USER"   # 적용은 재로그인 후(또는 `newgrp audio`)
```

### livekitwebrtcsrc 설치 (표준 apt 저장소에 없음 — 소스 빌드 필요)

`livekitwebrtcsrc`는 GStreamer 공식 Rust 플러그인(`gst-plugins-rs`)에
포함돼 있지만 `livekit` feature가 기본 빌드에는 꺼져 있어 **소스 빌드가
사실상 유일한 방법**이다(사전빌드 `.deb`를 배포하던 mopidy 저장소는 현재
Spotify 플러그인만 제공하며 webrtc 플러그인은 없다).

```bash
# 1) Rust 툴체인
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y
source "$HOME/.cargo/env"

# 2) 빌드 의존 패키지
sudo apt install -y build-essential pkg-config libssl-dev git \
  libgstreamer1.0-dev libgstreamer-plugins-base1.0-dev libgstreamer-plugins-bad1.0-dev

# 3) cargo-c (GStreamer 플러그인 C 라이브러리 빌드/설치 도구)
cargo install cargo-c

# 4) 빌드 + 홈 디렉토리에 설치 (sudo 불필요, ~10-20분)
cd ~
git clone --depth 1 https://github.com/GStreamer/gst-plugins-rs.git
cd gst-plugins-rs/net/webrtc
cargo cinstall --prefix="$HOME/.local" --libdir="$HOME/.local/lib" --features livekit

# 5) 확인
GST_PLUGIN_PATH="$HOME/.local/lib/gstreamer-1.0" gst-inspect-1.0 livekitwebrtcsrc
```

플러그인이 GStreamer 기본 prefix 밖(`~/.local`)에 설치됐으므로,
`GST_PLUGIN_PATH="$HOME/.local/lib/gstreamer-1.0"`를 실행 환경(아래 systemd
유닛의 `Environment=` 또는 셸)에 반드시 넣어야 한다.

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

## 리턴 메시지 발신 (LiveKit 텍스트 스트림)

파이프라인이 PLAYING 상태에 처음 도달하면 `{"type":"status","message":"스피커 연결됨","ts":...}`를,
ERROR/EOS 시에는 `{"type":"error"|"status", ...}`를 토픽 `rtic.status`로 발신한다.
스키마는 `rtic/web/src/messageSchema.js`(#9)와 반드시 일치해야 한다.

- 오디오 수신 참가자(`config.identity`)와 identity가 충돌하지 않도록 데이터 채널
  전용 참가자는 `<identity>-status`를 쓴다.
- 발신 실패(연결 불가 등)는 오디오 경로를 막지 않도록 로깅만 하고 무시한다 —
  리턴 메시지는 부가 기능이다.
- 로컬에서 실제 LiveKit(docker) + `@livekit/rtc-node` 리스너로 데몬이 보낸
  메시지가 정확한 스키마로 수신됨을 실제 검증했다.

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

## 트러블슈팅 — "연결은 되는데 스피커에서 소리가 안 남"

실제 배포 중 겪은 문제와 원인을 순서대로 좁히는 방법. 로그에
`원격 트랙 수신 시작: pad=audio_0`이 찍히면 오디오는 데몬까지 도달한
것이므로, 그 이후(출력) 단계만 보면 된다.

1. **서버 스피커 자체가 나는지** (데몬과 무관):
   `speaker-test -D hw:0,0 -t sine -f 440 -c 2 -l 1` — 안 나면 `audio`
   그룹(위 참고)·볼륨(`alsamixer`)·물리 연결 문제다. `aplay -l`로 카드
   번호를 확인해 `hw:카드,디바이스`를 맞춘다.
2. **`Auto-Mute Mode`**: `alsamixer`에서 이 항목이 `Enabled`면 헤드폰/
   특정 잭 연결 시 다른 출력을 자동 음소거한다 — `Disabled`로 바꾼다.
3. **sync=false (가장 흔한 원인)**: 파이프라인 로그에 `alsasink ...
   wrote 960 of 960`이 반복되는데도(=데이터는 정상 출력 중) 소리가
   없으면, WebRTC 지터로 오디오 클럭이 안 맞아 sink가 재생을 미루는
   것이다. sink에 `sync=false`를 붙인다(`RTIC_AUDIO_SINK`,
   `.env.example` 참고). 기본값에는 이미 포함돼 있다.
4. **포맷 불일치**: raw `hw:`는 샘플레이트/채널을 자동 변환하지 않아
   무음이 될 수 있다 — `plughw:`를 쓰면 ALSA가 변환해준다.
5. **정말 무음이 들어오는지 확인**: sink를
   `audioconvert ! wavenc ! filesink location=/tmp/x.wav`로 바꿔 몇 초
   녹음한 뒤 그 파일을 재생해본다. 파일에 소리가 있으면 수신은 정상이고
   실시간 sink 설정(위 3·4)만 문제, 파일도 무음이면 애초에 퍼블리시
   측(브라우저 마이크)이 무음을 보낸 것이다.

검증된 운영 sink 예: `RTIC_AUDIO_SINK=alsasink device=plughw:0,0 sync=false`
