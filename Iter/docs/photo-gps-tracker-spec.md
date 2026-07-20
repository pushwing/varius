# 구글 포토 GPS 동선 시각화 프로젝트 명세

## 1. 개요

구글 포토에서 사진을 선택하면 사진의 촬영 시간과 GPS 좌표를 추출하여 지도에 표시하고,
날짜별로 이동 동선을 시각적으로 보여주는 서비스.

### 목표
- 사용자가 구글 포토 Picker UI에서 사진을 직접 선택
- 선택된 사진의 EXIF 메타데이터(촬영 시간, GPS 좌표)를 추출
- 추출한 좌표/시간을 지도 위에 날짜별로 시각화 (마커 + 경로선)

### 제약 조건
- 한 번의 요청당 최대 **10장**까지만 가져올 수 있도록 제한
- 현재는 소수 사용자만 사용하지만, 향후 다수 사용자로 확장 가능성을 염두에 두고 설계

### 핵심 전제 (중요)
Google Photos API의 `mediaMetadata`는 `creationTime`, `width`, `height`, 카메라 정보만
제공하며 **GPS 좌표는 API 응답에 포함되지 않는다.** 위치 정보는 원본 파일의 EXIF
바이너리 안에만 존재하므로, 반드시 `baseUrl`로 원본 파일을 다운로드한 뒤
직접 EXIF를 파싱해야 한다.

---

## 2. 기술 스택

- 백엔드: PHP 8.2+, CodeIgniter 4
- 인증: Google OAuth2 (`photospicker.mediaitems.readonly` 스코프)
- 사진 선택: Google Photos Picker API
- EXIF 파싱: PHP 내장 `exif_read_data()` + HEIC 등 예외 포맷은 `exiftool` 바이너리 병행
- DB: MySQL (또는 기존 프로젝트 표준에 맞춤)
- 지도 시각화: Leaflet.js + OpenStreetMap
- 개발 도구: Claude Code, PHPUnit, PHPStan, GitHub Actions CI/CD

---

## 3. 전체 아키텍처

```
[사용자] → OAuth2 로그인 → [CI4 백엔드]
                              │
                              ▼
                    Picker 세션 생성 (최대 10장 제한)
                              │
                              ▼
                 사용자가 구글 포토 앱 UI에서 사진 선택
                              │
                              ▼
                    세션 폴링 (mediaItemsSet=true 대기)
                              │
                              ▼
                  선택된 mediaItems 목록 조회
                              │
                              ▼
              원본 파일 병렬 다운로드 (baseUrl + "=d")
                              │
                              ▼
                EXIF 파싱 (촬영 시간, GPS 좌표 추출)
                              │
                              ▼
                    DB 저장 (좌표/시간/참조 ID)
                              │
                              ▼
              지도 시각화 (날짜별 마커 + 경로선)
```

### 서비스 클래스 분리 (향후 MSA 확장 대비)

지금은 CI4 모놀리스 안에서 시작하되, 나중에 별도 서비스/워커로 분리하기 쉽도록
클래스 레벨에서 경계를 미리 나눈다.

```
app/Services/
├── GooglePhotosAuthService.php     // OAuth 토큰 발급/갱신
├── PhotoPickerService.php          // Picker 세션 생성/폴링/목록 조회
├── PhotoIngestService.php          // 원본 다운로드 + EXIF 추출 (핵심 로직)
└── RouteVisualizationService.php   // 날짜별 동선 조합, 지도 응답 데이터 생성
```

`PhotoIngestService`는 나중에 별도 워커로 분리될 가능성이 가장 큰 컴포넌트이므로,
HTTP 요청 컨텍스트에 의존하지 않는 순수 함수형으로 설계한다.
- 입력: `mediaItemId` 배열 + access token
- 출력: `[{lat, lng, taken_at, media_item_id}, ...]` 배열

이렇게 만들어두면 사용자 수가 늘었을 때 이 서비스만 큐 기반(SQS 등) 워커로
교체하고 나머지 API는 그대로 유지하는 점진적 전환이 가능하다.

---

## 4. API 연동 상세

### 4.1 OAuth2 인증
- 스코프: `https://www.googleapis.com/auth/photospicker.mediaitems.readonly`
- refresh token은 암호화하여 별도 `oauth_tokens` 테이블에 저장 (AES 암호화 권장)

### 4.2 Picker 세션 생성
```
POST https://photospicker.googleapis.com/v1/sessions
```
응답의 `pickerUri`로 사용자를 리다이렉트한다.

### 4.3 세션 폴링
```
GET https://photospicker.googleapis.com/v1/sessions/{sessionId}
```
`mediaItemsSet: true`가 될 때까지 폴링한다. 폴링 간격은 응답의
`pollingConfig.pollInterval` 값을 사용한다.

### 4.4 선택 항목 조회
```
GET https://photospicker.googleapis.com/v1/mediaItems?sessionId={sessionId}
```
- 반환된 항목이 10장을 초과하면 애플리케이션 레벨에서 잘라내거나,
  Picker 세션 생성 시 선택 가능 개수 제한 옵션이 있는지 최신 문서로 확인 후 적용

### 4.5 원본 다운로드
- `baseUrl + "=d"` 로 다운로드하면 EXIF가 포함된 원본 바이트를 받는다
- `baseUrl`은 **약 60분 후 만료**되므로 즉시 처리해야 한다
- 10장 제한 덕분에 `curl_multi_init`을 이용한 병렬 다운로드로 큐 시스템 없이
  동기 처리가 가능하다 (요청 하나 안에서 몇 초 내 완료)

---

## 5. EXIF 파싱

### 5.1 기본 파싱
```php
$exif = exif_read_data($filePath, 0, true);
```
GPS 태그(`GPSLatitude`, `GPSLatitudeRef`, `GPSLongitude`, `GPSLongitudeRef`)는
도분초(DMS) 형식의 배열로 반환되므로 십진수로 변환이 필요하다.

### 5.2 DMS → 십진수 변환
```php
function gpsToDecimal($exifCoord, $hemi) {
    $degrees = count($exifCoord) > 0 ? gps2Num($exifCoord[0]) : 0;
    $minutes = count($exifCoord) > 1 ? gps2Num($exifCoord[1]) : 0;
    $seconds = count($exifCoord) > 2 ? gps2Num($exifCoord[2]) : 0;
    $flip = ($hemi == 'W' or $hemi == 'S') ? -1 : 1;
    return $flip * ($degrees + $minutes / 60 + $seconds / 3600);
}

function gps2Num($coordPart) {
    $parts = explode('/', $coordPart);
    if (count($parts) <= 0) return 0;
    if (count($parts) == 1) return $parts[0];
    return floatval($parts[0]) / floatval($parts[1]);
}
```

### 5.3 예외 포맷 처리
- HEIC(아이폰 사진) 등은 PHP 내장 `exif_read_data()`가 읽지 못하는 경우가 많음
- `exiftool` 바이너리를 `shell_exec()`로 호출하거나 `php-exif` 라이브러리 사용을
  대체 경로로 마련

### 5.4 좌표 정확도 처리
- 실내 촬영 등으로 GPS 정확도가 낮은 사진은 지도가 지저분해질 수 있음
- 이전 지점과 비교해 비현실적인 이동 속도(예: 시속 200km 이상)가 감지되면
  이상치로 제외하는 필터링 로직 고려

---

## 6. DB 스키마

```sql
CREATE TABLE photo_locations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    google_media_item_id VARCHAR(255) NOT NULL,
    lat DECIMAL(10,7),
    lng DECIMAL(10,7),
    taken_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_user_date (user_id, taken_at)
);

CREATE TABLE oauth_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    refresh_token_encrypted TEXT NOT NULL,
    access_token_encrypted TEXT,
    expires_at DATETIME,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY idx_user (user_id)
);
```

### 저장 정책
- 원본 이미지 파일은 저장하지 않는다 (스토리지 비용 절감).
  EXIF 추출 후 즉시 폐기하고 좌표/시간/`media_item_id`만 DB에 저장
- 화면에 사진 자체를 다시 표시해야 할 경우, 그 시점에 API로 재조회하여
  새 `baseUrl`을 발급받는다 (캐싱 불가, 매번 재요청 구조)

---

## 7. 확장성 고려사항

1. **레이트 리밋**: CI4 필터로 "요청당 10장, 사용자당 시간당 N회 세션 생성 제한"을
   초기 단계부터 적용
2. **API 쿼터 모니터링**: Google Photos API는 프로젝트 단위 쿼터가 있으므로
   사용자 증가에 따른 쿼터 소진 속도를 모니터링
3. **워커 분리 시점**: 동시 사용자가 늘어 동기 처리로 응답 지연이 발생하면
   `PhotoIngestService`를 큐 기반 워커(SQS + 컨테이너 등)로 분리

---

## 8. 구현 작업 순서 (Claude Code 작업용 체크리스트)

- [ ] 1. `GooglePhotosAuthService` — OAuth2 로그인/콜백/토큰 갱신 구현
- [ ] 2. `oauth_tokens` 테이블 마이그레이션 작성 및 토큰 암호화 저장 로직
- [ ] 3. `PhotoPickerService` — 세션 생성, 폴링, 목록 조회 구현
- [ ] 4. 세션 생성 시 10장 제한 적용 (요청 파라미터 또는 후처리 검증)
- [ ] 5. `PhotoIngestService` — 원본 병렬 다운로드 (`curl_multi_init`) 구현
- [ ] 6. EXIF 파싱 로직 구현 (DMS→십진수 변환 포함, HEIC 예외 처리)
- [ ] 7. `photo_locations` 테이블 마이그레이션 작성
- [ ] 8. 좌표 이상치 필터링 로직 구현
- [ ] 9. `RouteVisualizationService` — 날짜별 동선 조회 API 엔드포인트 구현
- [ ] 10. 프론트엔드 Leaflet.js 지도 컴포넌트 구현 (날짜별 색상 구분, 마커/경로선)
- [ ] 11. 레이트 리밋 필터 적용
- [ ] 12. PHPUnit 테스트 작성 (특히 EXIF 파싱, 좌표 변환 로직)
- [ ] 13. PHPStan 정적 분석 통과 확인
- [ ] 14. GitHub Actions CI/CD 파이프라인에 통합
