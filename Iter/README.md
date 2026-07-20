# Iter — Google Photos GPS 동선 시각화

구글 포토에서 사진을 선택하면 촬영 시간과 GPS 좌표를 추출해 지도에 표시하고,
**날짜별 이동 동선**(마커 + 경로선)으로 시각화하는 서비스입니다.
프로젝트명 *Iter*는 라틴어로 여정·경로를 뜻합니다.

## 동작 흐름

```
[사용자] → OAuth2 로그인 → Picker 세션 생성(최대 10장)
   → 구글 포토 앱에서 사진 선택 → 세션 폴링(mediaItemsSet=true)
   → 선택 항목 조회 → 원본 병렬 다운로드(baseUrl + "=d")
   → EXIF 파싱(촬영 시간·GPS 좌표) → DB 저장(좌표/시간/참조 ID)
   → 지도 시각화(날짜별 마커 + 경로선)
```

> **왜 EXIF를 직접 파싱하나?** Google Photos API는 촬영 시간·카메라 정보만 주고 **GPS 좌표는 응답에 없습니다.**
> 위치는 원본 파일의 EXIF 안에만 있으므로 원본을 받아 직접 파싱합니다. `baseUrl`은 약 60분 뒤 만료되므로 즉시 처리합니다.

## 구성

- **CI4 백엔드** — OAuth2 인증, Picker 세션 관리, EXIF 추출, 동선 API
  - `GooglePhotosAuthService` — OAuth 토큰 발급/갱신
  - `PhotoPickerService` — Picker 세션 생성·폴링·목록 조회
  - `PhotoIngestService` — 원본 병렬 다운로드 + EXIF 추출(향후 큐 워커로 분리 대비, 순수 함수형)
  - `RouteVisualizationService` — 날짜별 동선 조합·지도 응답 생성
- **인증** — Google OAuth2 (`photospicker.mediaitems.readonly`), refresh token 암호화 저장
- **EXIF** — PHP `exif_read_data()` + HEIC 등은 `exiftool`/`php-exif` 병행, DMS→십진수 변환·이상치 필터링
- **DB** — MySQL (`photo_locations`, `oauth_tokens`) — 원본 이미지는 저장하지 않고 좌표·시간·참조 ID만 보관
- **프론트엔드** — Leaflet.js + OpenStreetMap, 날짜별 색상 구분 마커·경로선

## 제약

- 한 번의 요청당 최대 **10장**. 이 제한 덕분에 `curl_multi_init` 병렬 다운로드로 큐 없이 동기 처리합니다.
- 요청당 10장 + 사용자당 시간당 세션 생성 횟수 레이트 리밋을 초기부터 적용합니다.

상세 명세는 [`docs/photo-gps-tracker-spec.md`](docs/photo-gps-tracker-spec.md)를,
Claude Code 작업 규칙은 [`CLAUDE.md`](CLAUDE.md)를 참고하세요.

## 개발 순서

명세 8절 체크리스트에 따라 순차 구현합니다.

- [ ] `GooglePhotosAuthService` — OAuth2 로그인/콜백/토큰 갱신
- [ ] `oauth_tokens` 마이그레이션 + 토큰 암호화 저장
- [ ] `PhotoPickerService` — 세션 생성·폴링·목록 조회
- [ ] 세션 10장 제한 적용(요청 파라미터 또는 후처리 검증)
- [ ] `PhotoIngestService` — 원본 병렬 다운로드(`curl_multi_init`)
- [ ] EXIF 파싱(DMS→십진수 변환, HEIC 예외 처리)
- [ ] `photo_locations` 마이그레이션
- [ ] 좌표 이상치 필터링
- [ ] `RouteVisualizationService` — 날짜별 동선 조회 API
- [ ] Leaflet.js 지도 컴포넌트(날짜별 색상·마커·경로선)
- [ ] 레이트 리밋 필터
- [ ] PHPUnit 테스트(EXIF 파싱·좌표 변환 중심)
- [ ] PHPStan 정적 분석 통과

> 명세 8절의 GitHub Actions CI/CD 항목은 이 모노레포 정책(CI/CD 미사용·로컬 검증)에 따라 생략하고
> `composer ci`(CS Fixer → PHPStan → PHPUnit)를 로컬에서 실행합니다.
