# Iter — Google Takeout zip 업로드 기반 GPS 적재로 전환

> 대상: Picker API 기반 원본 다운로드+EXIF 추출 파이프라인을 Google Takeout zip 업로드 기반으로 전면 교체.
> 관련: 이슈 #14/#20(Picker, 폐기)·#21/#22(Ingest, 폐기)·#23(지도, 유지), `docs/photo-gps-tracker-spec.md`.

## 1. 배경 / 문제

실서버 디버깅 결과, **Google Photos Picker API·Library API 모두 다운로드 원본에서 GPS EXIF를 의도적으로 제거**함을 Google 공식 문서로 확인했다:

> "If you want to download the image retaining all the Exif metadata except the location metadata, concatenate the base URL with the `d` parameter." — [Picker API 공식 문서](https://developers.google.com/photos/picker/guides/media-items)

즉 현재 아키텍처(Picker 선택 → 원본 다운로드 → EXIF에서 GPS 추출)는 **어떤 사진을 선택해도 구조적으로 GPS를 얻을 수 없다.** Google이 GPS를 원본 그대로 제공하는 유일한 경로는 **Google Takeout**(사용자가 직접 내보내기를 요청하는 비동기 벌크 내보내기) 뿐이며, Takeout은 사진 파일 자체에서도 GPS를 제거하지만 `.json` 사이드카 파일에 `geoData`로 별도 보존한다.

## 2. 목표 / 완료 조건

- 사용자가 Google Takeout에서 직접 내보낸 zip을 업로드하면, 안의 사진들의 GPS 좌표·촬영 시각을 추출해 `photo_locations`에 저장하고 지도에서 볼 수 있다.
- Picker 기반 파이프라인은 완전히 제거한다(더는 도달 불가능한 목표이므로 죽은 코드).
- 200장 상한, 동기 처리(큐 인프라 신규 구축 안 함).
- `composer ci` 통과 + 신규 테스트 + 실제 브라우저로 업로드→지도 확인.

## 3. 확정된 설계 결정

| 결정 | 선택 | 이유 |
|------|------|------|
| 처리 방식 | **동기(업로드 요청 안에서 바로 처리) + 200장 상한** | 큐 인프라(Redis 등)가 아직 없음. Takeout은 날짜/앨범 범위를 사용자가 직접 조절해 내보낼 수 있어 상한이 있어도 실용적 |
| GPS 필드 우선순위 | `geoData` 우선, 둘 다 0.0이면 `geoDataExif` 폴백, 그래도 0.0이면 위치 없음 | Takeout JSON은 `geoData`(Google 보정값)와 `geoDataExif`(원본 EXIF값) 두 필드를 모두 제공하며, 위치 없음은 `{0.0, 0.0}`으로 표현됨(공식 동작) |
| 사진 ↔ JSON 매칭 | zip 내 `*.json` 파일명에서 `.json` 접미사를 뗀 이름으로 정확히 일치하는 미디어 파일만 매칭 | Google의 파일명 46자 절단·중복 넘버링 등 변칙 케이스는 흔치 않고, 매칭 실패는 해당 항목만 조용히 스킵(전체 실패 아님)하면 충분 — MVP 스코프에서 완벽한 퍼지 매칭은 과설계 |
| DB 컬럼 | `google_media_item_id` → **`source_item_id`로 rename**(신규 ALTER 마이그레이션, 값은 zip 안 파일명) | 더는 Google mediaItemId가 아니므로 옛 이름을 유지하면 오해 소지. 이 흐름으로 저장된 기존 데이터가 없어(항상 0장 저장) 마이그레이션 리스크 없음 |
| 재업로드 idempotency | `(user_id, source_item_id)` 유니크 제약 그대로 재사용 | 파일명 기반 한계(다른 사진이 같은 이름이면 스킵)는 있으나 MVP로 충분 |
| Picker 관련 코드 | **완전 삭제**(서비스·컨트롤러·라우트·테스트·`GoogleApiUsageTracker`) | 도달 불가능한 목표를 위한 코드는 죽은 코드. `GoogleApiUsageTracker`도 더는 호출할 Google API(Picker/다운로드)가 없어 함께 삭제 |
| OAuth 스코프 | `photospicker.mediaitems.readonly` 제거, `openid email profile`만 유지 | Picker API를 더는 호출하지 않으므로 불필요한 권한 요청 제거(최소 권한 원칙) |
| 로그인 유지 여부 | 유지(사용자 식별·세션 관리용) | Takeout 처리 자체엔 Google API 호출이 필요 없지만, 사용자별 데이터 격리를 위해 기존 로그인 흐름은 그대로 둔다 |
| 업로드 라우트 레이트 리밋 | 기존 `SessionRateLimitFilter`를 `/takeout/upload`에 재적용 | 무거운 동기 처리이므로 남용 방지가 더 중요해짐. 새 필터를 만들지 않고 기존 것을 재활용 |
| 썸네일 | 유지(zip에서 압축 해제한 실제 사진 파일로 생성) | 기존 `GdThumbnailGenerator`·저장 정책(300px 예외) 그대로 적용 가능 |

## 4. 아키텍처

### 4.1 삭제 대상 (전부 도달 불가능해진 죽은 코드)

- `app/Services/PhotoPickerService.php`
- `app/Controllers/PickerController.php`
- `app/Services/PhotoIngestService.php`
- `app/Services/Ingest/CurlMultiDownloader.php`
- `app/Services/Ingest/MediaItemDownloaderInterface.php`
- `app/Services/Ingest/NativeExifExtractor.php`
- `app/Services/Ingest/ExifToolExtractor.php`
- `app/Services/Ingest/FallbackExifExtractor.php`
- `app/Services/Ingest/ExifGpsParser.php`
- `app/Services/Ingest/ExifExtractorInterface.php`
- `app/Services/GoogleApiUsageTracker.php`
- `app/Enums/GoogleApiName.php`
- `Config\Services`의 `photoPicker()`·`photoIngest()`·`googleApiUsageTracker()` 팩토리
- `app/Config/Routes.php`의 `picker/*` 라우트 전부
- 대응하는 테스트 전부(`PhotoPickerServiceTest`, `PickerControllerTest`, `PhotoIngestServiceTest`, `CurlMultiDownloaderTest`, `NativeExifExtractorTest`, `ExifToolExtractorTest`, `FallbackExifExtractorTest`, `ExifGpsParserTest`, `GoogleApiUsageTrackerTest`, `RecordingCurlMultiDownloader` 테스트 더블)

### 4.2 유지·재사용

- `app/Services/Ingest/PhotoLocation.php`, `ExifLocation.php` — 그대로(또는 `ExifLocation`은 `TakeoutLocation` 등으로 개념 정리 없이 필드가 동일하므로 재사용).
- `app/Services/Ingest/ThumbnailGeneratorInterface.php`, `GdThumbnailGenerator.php` — 그대로.
- `app/Services/RouteVisualizationService.php`, `app/Controllers/RouteController.php` — 무변경.
- `app/Models/PhotoLocationModel.php` — 컬럼명만 `source_item_id`로 갱신.
- `app/Filters/SessionRateLimitFilter.php` — 캐시 키 prefix만 라우트 성격에 맞게 조정(선택), `/takeout/upload`에 적용.
- `app/Services/GooglePhotosAuthService.php` — 유지(로그인용), Picker 스코프만 `Config\GoogleOAuth`에서 제거.

### 4.3 신설

**`app/Services/Ingest/TakeoutMetadataParser.php`**(순수, I/O 없음)
- 입력: Takeout JSON 사이드카를 `json_decode`한 배열.
- 출력: `?ExifLocation`(`lat`, `lng`, `takenAt`).
- `geoData.latitude/longitude` → 둘 다 0.0이면 `geoDataExif`로 폴백 → 그래도 0.0이면 `null`.
- `photoTakenTime.timestamp`(문자열, Unix epoch 초) → `date('Y-m-d H:i:s', (int) $timestamp)`.

**`app/Services/TakeoutIngestService.php`**
- 입력: 업로드된 zip의 로컬 경로, 대상 `user_id`.
- 처리:
  1. `ZipArchive`로 열기(실패 시 예외).
  2. 임시 디렉터리(`writable/uploads/takeout_{uniqid}/`)에 압축 해제.
  3. 재귀적으로 `*.json` 파일을 찾아, 파일명에서 `.json`을 뗀 이름과 정확히 일치하는 미디어 파일을 같은 디렉터리에서 탐색. 못 찾으면 스킵.
  4. `TakeoutMetadataParser`로 파싱, `null`이면 스킵.
  5. 최대 200개까지만 처리(초과분은 무시하고 처리된 개수·전체 후보 개수를 함께 반환해 컨트롤러가 안내할 수 있게 함).
  6. 각 항목에 대해 `GdThumbnailGenerator::generate()` 호출(선택 주입, 실패해도 계속).
  7. `PhotoIngestService`에서 이식한 이상치 필터링(직전 지점 대비 시속 200km 초과 제외) 적용.
  8. 압축 해제한 임시 디렉터리 전체 삭제(원본 미보관 정책).
- 출력: `list<PhotoLocation>` + 메타 정보(`array{locations: list<PhotoLocation>, totalCandidates: int}` 형태로 확장).

**`app/Controllers/TakeoutController.php`**
- `POST /takeout/upload`(multipart, 필드명 `file`) — 로그인 가드(401) → 확장자/MIME `.zip` 검증(아니면 422) → 파일 크기 검증(앱 레벨 상한, 초과 시 413) → 업로드 파일을 `writable/uploads/`로 이동 → `TakeoutIngestService::ingest()` 호출 → `PhotoLocationModel::saveMany()` 저장 → `{saved: N, totalCandidates: M}` JSON 응답 → 업로드 원본 zip도 처리 후 삭제.

### 4.4 DB 마이그레이션

신규 `AddSourceItemIdRenameToPhotoLocations`(또는 CI4 `renameColumn` 사용) — `google_media_item_id` → `source_item_id`. 유니크 인덱스(`uniq_photo_locations_user_media`)는 컬럼명 변경에 맞춰 재생성.

### 4.5 홈 화면 UI

`app/Views/home.php`의 Picker 흐름 JS(상태 머신 전체)를 제거하고 zip 업로드 폼으로 교체:
- 안내 문구 + [takeout.google.com](https://takeout.google.com) 링크.
- `<form enctype="multipart/form-data">` + `<input type="file" accept=".zip" name="file">` + 업로드 버튼.
- 업로드 중 버튼 비활성화 + "처리 중..." 표시(동기 처리, `fetch` 응답 대기).
- 완료: "N장 저장됨"(전체 후보가 200장 넘으면 "M장 중 상한 200장만 처리됨" 안내) + "지도에서 보기".
- 실패: 에러 메시지 + 다시 시도. 401이면 재로그인 안내(기존 패턴 유지).

## 5. 테스트 계획

- `TakeoutMetadataParserTest`(순수 단위): `geoData` 정상 파싱 / `geoData` 0,0 → `geoDataExif` 폴백 / 둘 다 0,0 → null / `photoTakenTime.timestamp` 변환 / 필드 자체 없음 → null.
- `TakeoutIngestServiceTest`(단위, 실제 임시 zip 픽스처를 테스트에서 직접 생성): 정상 매칭·짝 없는 json 스킵·200장 상한·이상치 필터·임시 파일 정리 확인.
- `TakeoutControllerTest`(feature): 비로그인 401 / zip 아닌 파일 422 / 정상 업로드 시 저장 건수 응답 및 DB 반영(`PhotoLocationModel` mock 아님, 실제 DB).
- `PhotoLocationModelTest`: `source_item_id` 컬럼명 반영해 갱신.
- `composer ci`(CS Fixer → PHPStan → PHPUnit) 통과.
- 실제 브라우저: 로그인 → zip 업로드 → "N장 저장됨" → 지도에서 마커 확인(수동, Takeout 실제 내보내기 필요).

## 6. 범위 밖

- 비동기(큐 기반) 처리 — 현재 인프라로 불필요, 필요해지면 후속 이슈.
- 여러 zip 파트(2GB 단위 분할) 동시 업로드 — 1회 1개 zip만 지원, 사용자가 Takeout에서 날짜/앨범 범위로 크기를 조절.
- 파일명 매칭 실패 케이스에 대한 퍼지 매칭(중복 넘버링·46자 절단 등 Google의 알려진 변칙) — 스킵으로 충분, 필요해지면 후속 개선.
- 기존 `photo_locations`에 남아있을 수 있는 Picker 기반 데이터 마이그레이션 — 이 흐름으로 저장된 데이터가 없어 해당 없음.
