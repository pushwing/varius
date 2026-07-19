# rtic 외부용 웹 앱

공중 인터넷에서 접속하는 클라이언트(웹) — 로그인, 목소리 전송(마이크 퍼블리시),
리눅스 데몬이 보내는 리턴 메시지 표시를 담당합니다. 프레임워크 없이 바닐라
JS + [livekit-client](https://github.com/livekit/client-sdk-js) + Vite로
구성했습니다(가족용 인터콤 규모라 프레임워크 오버헤드가 불필요하다고 판단).

## 구성

- `src/api.js` — CI4 `/api/v1/tokens` 로그인 요청(테스트 가능한 순수 함수)
- `src/messageSchema.js` — 리턴 메시지 스키마 파싱/검증(아래 참고)
- `src/main.js` — DOM 와이어링: 로그인 폼 → LiveKit `Room` 연결 → 마이크 토글 → 메시지 로그 렌더링

## 리턴 메시지 스키마 (이슈 #9에서 확정)

LiveKit 데이터 채널 **텍스트 스트림** 토픽 `rtic.status`로 전달되는 JSON:

```json
{ "type": "status" | "info" | "error", "message": "사람이 읽는 텍스트", "ts": 1700000000 }
```

- `ts`는 유닉스 초.
- 앱은 `room.registerTextStreamHandler('rtic.status', ...)`으로 수신해 파싱·렌더링한다.
- ⚠️ **리눅스 데몬(`../daemon/`) 쪽의 실제 발신 구현은 이 이슈 범위 밖**이다.
  데몬은 현재 GStreamer `livekitwebrtcsrc`로 오디오만 수신하며, 텍스트
  스트림 송신에는 LiveKit Python SDK(`livekit.rtc`)가 별도로 필요하다 —
  후속 이슈에서 다룬다.

## 로컬 개발

```bash
npm install
cp .env.example .env   # VITE_API_BASE_URL을 CI4 API 주소로 설정
npm run dev
```

CI4 API가 웹 앱과 다른 오리진이면 CORS 설정이 필요하다 — `rtic/.env`의
`cors.allowedOrigins`에 웹 앱 오리진(예: `http://localhost:5173`)을 추가한다
(`app/Config/Cors.php`, `app/Config/Filters.php` 참고. 이 이슈에서 함께 추가함).

## 검증

```bash
npm run lint          # eslint
npm run format:check  # prettier
npm run test          # vitest (api.js, messageSchema.js 단위 테스트 14건)
npm run ci             # 위 세 개 순차 실행
```

로컬 docker LiveKit + CI4 dev 서버 + 이 앱을 모두 띄우고 실제 브라우저로
로그인 → CORS 프리플라이트 → 토큰 발급 → LiveKit 시그널링 인증까지
end-to-end로 확인했다(서버 로그에서 우리가 발급한 VideoGrant가 정확히
전달됨을 확인). 이 개발 환경(브라우저 프리뷰 ↔ 로컬 Docker LiveKit) 간의
실제 ICE/미디어 경로는 네트워크 토폴로지 제약으로 끝까지 확인하지
못했다 — 실제 배포망에서 재확인이 필요하다.
