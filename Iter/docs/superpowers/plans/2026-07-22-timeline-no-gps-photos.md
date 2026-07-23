# GPS 없는 사진도 시간표에 노출 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** GPS 좌표가 없어도 촬영 시각이 있는 사진은 업로드 파이프라인에서 살아남아
시간표(`GET /timeline/{date}`)에 노출되도록 한다. 지도(`/map`)와 여행 이동거리·방문 지점
통계는 좌표 있는 사진만 계속 사용한다.

**Architecture:** `lat`/`lng`를 파서(`ExifLocation`)와 값객체(`PhotoLocation`) 전 구간에서
`nullable`로 관통시킨다. 업로드 서비스의 candidate 채택 조건은 이미 "촬영 시각 유무"만
보므로 파서만 고치면 자동으로 좌표 없는 사진도 통과한다. 이상치 필터(속도 기반)는 좌표 없는
지점을 항상 유지하고 앵커 갱신에서 제외한다. 지도·시간표·여행 통계 3개 소비처는 각자
좌표 없는 행을 어떻게 다룰지(제외/편입/제외)를 스펙대로 개별 처리한다.

**Tech Stack:** PHP 8.4+ / CodeIgniter 4, PHPUnit 10.5(in-memory SQLite), PHPStan 레벨 6.

## Global Constraints

- `declare(strict_types=1)`, PSR-12, PHPStan 레벨 6 통과 필수.
- 변경/신규 메서드는 제네릭 타입(`list<array{...}>` 등) PHPDoc 명시 필수.
- TDD 순서 엄수: RED(실패 확인) → GREEN(최소 구현) → 커밋.
- 커밋 메시지: 이모지 + Conventional Commits 접두어 + 한국어 설명 + `(#29)`.
- **지도(`/map`)는 좌표 없는 사진을 완전히 제외**한다(마커·경로선 자체가 불가능).
- **시간표(`TimelineService`)는 좌표 없는 사진을 직전 세그먼트에 편입**한다(직전 세그먼트가
  없으면 `lat: null, lng: null`인 새 세그먼트를 연다).
- **여행 통계(`TripStatsService`)는 좌표 없는 사진을 이동거리·방문 지점 계산에서 제외**한다.
  `TripSummaryService`(날짜별 사진 수)는 좌표를 쓰지 않으므로 변경 없음.
- DB 스키마 변경 없음 — `photo_locations.lat`/`lng`는 이미 `nullable`.
- 새 라우트 없음.

---

### Task 1: 파서 + 값객체 — nullable 좌표 관통

**Files:**
- Modify: `app/Services/Ingest/ExifLocation.php`(전체 교체)
- Modify: `app/Services/Ingest/PhotoLocation.php`(전체 교체)
- Modify: `app/Services/Ingest/TakeoutMetadataParser.php:19-27`(`parse()` 메서드)
- Modify: `app/Services/Ingest/PhotoExifParser.php:20-33`(`parse()` 메서드)
- Test: `tests/unit/TakeoutMetadataParserTest.php`, `tests/unit/PhotoExifParserTest.php`

**Interfaces:**
- Produces: `App\Services\Ingest\ExifLocation`의 `lat`/`lng`가 `?float`로 변경.
  `App\Services\Ingest\PhotoLocation`의 `lat`/`lng`도 `?float`로 변경(생성자 시그니처:
  `__construct(string $mediaItemId, ?float $lat, ?float $lng, string $takenAt, ?string $thumbnailPath = null)`).
  `TakeoutMetadataParser::parse()`, `PhotoExifParser::parse()` 둘 다 "좌표는 없어도 촬영
  시각이 있으면 `ExifLocation(null, null, $takenAt)`을 반환"하는 동작으로 바뀐다. Task 2가
  이 동작에 의존한다(업로드 서비스 쪽 코드는 이 변경만으로 자동으로 원하는 동작을 하게 되어
  수정이 필요 없다).

- [ ] **Step 1: 실패하는 테스트 작성**

`tests/unit/TakeoutMetadataParserTest.php`의 55-101번째 줄(`testFallsBackToGeoDataExifWhenGeoDataIsZero`
끝부터 `testReturnsNullWhenLatitudeIsNonNumeric` 끝까지)을 다음으로 교체한다:

```php
    public function testFallsBackToGeoDataExifWhenGeoDataIsZero(): void
    {
        $location = $this->parser->parse([
            'geoData' => ['latitude' => 0.0, 'longitude' => 0.0],
            'geoDataExif' => ['latitude' => 35.1796, 'longitude' => 129.0756],
            'photoTakenTime' => ['timestamp' => '1563490529'],
        ]);

        $this->assertNotNull($location);
        $this->assertEqualsWithDelta(35.1796, $location->lat, 0.0001);
        $this->assertEqualsWithDelta(129.0756, $location->lng, 0.0001);
    }

    public function testReturnsLocationWithNullCoordsWhenBothGeoDataAndGeoDataExifAreZero(): void
    {
        // 좌표는 위치 없음으로 판정되지만, 촬영 시각이 있으면 사진 자체는 살려야 한다
        // (GPS 없는 사진도 시간표에는 노출).
        $location = $this->parser->parse([
            'geoData' => ['latitude' => 0.0, 'longitude' => 0.0],
            'geoDataExif' => ['latitude' => 0.0, 'longitude' => 0.0],
            'photoTakenTime' => ['timestamp' => '1563490529'],
        ]);

        $this->assertNotNull($location);
        $this->assertNull($location->lat);
        $this->assertNull($location->lng);
        $this->assertSame('2019-07-18 22:55:29', $location->takenAt);
    }

    public function testReturnsLocationWithNullCoordsWhenGeoDataFieldsMissing(): void
    {
        $location = $this->parser->parse(['photoTakenTime' => ['timestamp' => '1563490529']]);

        $this->assertNotNull($location);
        $this->assertNull($location->lat);
        $this->assertNull($location->lng);
        $this->assertSame('2019-07-18 22:55:29', $location->takenAt);
    }

    public function testReturnsNullWhenNoCoordinatesAndNoTimestamp(): void
    {
        // 좌표도 촬영 시각도 없으면 동선에 쓸 수 없어 완전히 버려진다.
        $this->assertNull($this->parser->parse([]));
    }

    public function testTakenAtIsNullWhenTimestampMissing(): void
    {
        $location = $this->parser->parse([
            'geoData' => ['latitude' => 37.5665, 'longitude' => 126.9780],
        ]);

        $this->assertNotNull($location);
        $this->assertNull($location->takenAt);
    }

    public function testReturnsLocationWithNullCoordsWhenLatitudeIsNonNumeric(): void
    {
        $location = $this->parser->parse([
            'geoData' => ['latitude' => 'invalid', 'longitude' => 126.9780],
            'photoTakenTime' => ['timestamp' => '1563490529'],
        ]);

        $this->assertNotNull($location);
        $this->assertNull($location->lat);
        $this->assertNull($location->lng);
    }
```

`tests/unit/PhotoExifParserTest.php`의 65-92번째 줄(`testReturnsNullWhenGpsMissing`부터
`testReturnsNullWhenCoordinatesAreZero` 끝까지)을 다음으로 교체한다:

```php
    public function testReturnsLocationWithNullCoordsWhenGpsMissing(): void
    {
        // GPS 가 없어도 촬영 시각이 있으면 사진 자체는 살려야 한다(시간표 노출용).
        $result = (new PhotoExifParser())->parse([
            'DateTimeOriginal' => '2019:07:18 22:55:29',
        ]);

        $this->assertNotNull($result);
        $this->assertNull($result->lat);
        $this->assertNull($result->lng);
        $this->assertSame('2019-07-18 13:55:29', $result->takenAt);
    }

    public function testReturnsLocationWithNullCoordsWhenGpsMalformed(): void
    {
        $result = (new PhotoExifParser())->parse($this->exif([
            'GPSLatitude' => 'not-an-array',
        ]));

        $this->assertNotNull($result);
        $this->assertNull($result->lat);
        $this->assertNull($result->lng);
    }

    public function testReturnsNullWhenNoGpsAndNoDateTime(): void
    {
        // 좌표도 촬영 시각도 없으면 동선에 쓸 수 없어 완전히 버려진다.
        $this->assertNull((new PhotoExifParser())->parse([]));
    }

    public function testReturnsLocationWithNullCoordsWhenCoordinatesAreZero(): void
    {
        // (0,0) 은 위치 없음으로 간주(Takeout 파서와 동일한 취급) — 촬영 시각은 있으므로 살린다.
        $result = (new PhotoExifParser())->parse($this->exif([
            'GPSLatitude' => ['0/1', '0/1', '0/1'],
            'GPSLongitude' => ['0/1', '0/1', '0/1'],
        ]));

        $this->assertNotNull($result);
        $this->assertNull($result->lat);
        $this->assertNull($result->lng);
    }
```

- [ ] **Step 2: 테스트가 실패하는지 확인**

Run: `vendor/bin/phpunit tests/unit/TakeoutMetadataParserTest.php tests/unit/PhotoExifParserTest.php`
Expected: FAIL — 새/수정된 테스트들이 `assertNotNull($location)` 또는 `assertNull($location->lat)`
단계에서 실패(현재 구현은 좌표 없으면 `parse()`가 통째로 `null`을 반환하므로
`assertNotNull`이 깨지거나, `$location->lat`에 접근할 때 null 참조 에러가 난다).

- [ ] **Step 3: ExifLocation, PhotoLocation nullable화**

`app/Services/Ingest/ExifLocation.php` 전체를 다음으로 교체:

```php
<?php

declare(strict_types=1);

namespace App\Services\Ingest;

/**
 * EXIF/사이드카에서 추출한 단일 사진의 위치·시간.
 *
 * lat/lng 는 GPS 정보가 없는 사진도 시간표에 노출하기 위해 nullable 이다(위치는
 * 비워두고 촬영 시각만으로 시간표에 얹는다). takenAt 도 EXIF 에 촬영 시각이 없을
 * 수 있어 nullable('Y-m-d H:i:s' 형식).
 */
final readonly class ExifLocation
{
    public function __construct(
        public ?float $lat,
        public ?float $lng,
        public ?string $takenAt,
    ) {
    }
}
```

`app/Services/Ingest/PhotoLocation.php` 전체를 다음으로 교체:

```php
<?php

declare(strict_types=1);

namespace App\Services\Ingest;

/**
 * 동선 한 점 — media_item_id + 좌표(있으면) + 촬영 시각 + (있으면) 썸네일 경로.
 *
 * TakeoutIngestService/PlainZipIngestService 의 출력 단위이며, 후속 저장 단계에서
 * photo_locations 로 적재된다. lat/lng 는 GPS 없이 촬영 시각만 있는 사진도 시간표에
 * 노출하기 위해 nullable 이다(지도·이동거리 통계에서는 좌표 있는 사진만 사용한다).
 * 썸네일은 지도 미리보기 표시용 예외 보관 대상이다(Iter/CLAUDE.md 저장 정책).
 */
final readonly class PhotoLocation
{
    public function __construct(
        public string $mediaItemId,
        public ?float $lat,
        public ?float $lng,
        public string $takenAt,
        public ?string $thumbnailPath = null,
    ) {
    }

    /**
     * DB 저장·직렬화를 위한 배열 표현(컬럼명 snake_case).
     *
     * @return array{media_item_id: string, lat: float|null, lng: float|null, taken_at: string, thumbnail_path: string|null}
     */
    public function toArray(): array
    {
        return [
            'media_item_id' => $this->mediaItemId,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'taken_at' => $this->takenAt,
            'thumbnail_path' => $this->thumbnailPath,
        ];
    }
}
```

`app/Services/Ingest/TakeoutMetadataParser.php`의 `parse()` 메서드(19-27번째 줄)를 다음으로
교체(그 아래 `coordinates()`/`takenAt()` private 메서드는 변경하지 않는다):

```php
    public function parse(array $json): ?ExifLocation
    {
        $coords = $this->coordinates($json, 'geoData') ?? $this->coordinates($json, 'geoDataExif');
        $takenAt = $this->takenAt($json);

        if ($coords === null && $takenAt === null) {
            return null; // 좌표도 촬영 시각도 없으면 동선에 쓸 수 없다.
        }

        return new ExifLocation($coords[0] ?? null, $coords[1] ?? null, $takenAt);
    }
```

`app/Services/Ingest/PhotoExifParser.php`의 `parse()` 메서드(20-33번째 줄)를 다음으로
교체(그 아래 `coordinate()`/`toFloat()`/`takenAt()` private 메서드는 변경하지 않는다):

```php
    public function parse(array $exif): ?ExifLocation
    {
        $lat = $this->coordinate($exif, 'GPSLatitude', 'GPSLatitudeRef', ['S']);
        $lng = $this->coordinate($exif, 'GPSLongitude', 'GPSLongitudeRef', ['W']);

        $coords = null;
        if ($lat !== null && $lng !== null && ! ($lat === 0.0 && $lng === 0.0)) {
            $coords = [$lat, $lng];
        }

        $takenAt = $this->takenAt($exif);

        if ($coords === null && $takenAt === null) {
            return null; // 좌표도 촬영 시각도 없으면 동선에 쓸 수 없다.
        }

        return new ExifLocation($coords[0] ?? null, $coords[1] ?? null, $takenAt);
    }
```

- [ ] **Step 4: 테스트 통과 확인**

Run: `vendor/bin/phpunit tests/unit/TakeoutMetadataParserTest.php tests/unit/PhotoExifParserTest.php`
Expected: `OK (...)` — 두 파일 전체(기존 유지 케이스 + 수정 케이스 + 신규 케이스) 통과.

- [ ] **Step 5: 정적 분석 통과 확인**

Run: `composer analyse`
Expected: `[OK] No errors`

- [ ] **Step 6: 커밋**

```bash
git add app/Services/Ingest/ExifLocation.php app/Services/Ingest/PhotoLocation.php \
  app/Services/Ingest/TakeoutMetadataParser.php app/Services/Ingest/PhotoExifParser.php \
  tests/unit/TakeoutMetadataParserTest.php tests/unit/PhotoExifParserTest.php
git commit -m "$(cat <<'EOF'
✨ feat: GPS 없이 촬영 시각만 있는 사진도 파서가 살리도록 변경 (#29)

EOF
)"
```

---

### Task 2: 업로드 서비스 — 이상치 필터의 좌표-없음 방어

**Files:**
- Modify: `app/Services/AbstractZipIngestService.php:155-211`(`filterOutliers()`/`isReachable()`)
- Test: `tests/unit/TakeoutIngestServiceTest.php`, `tests/unit/PlainZipIngestServiceTest.php`

**Interfaces:**
- Consumes: Task 1의 `PhotoLocation`(`lat`/`lng`가 `?float`).
- Produces: `AbstractZipIngestService::filterOutliers(array $locations): array`가 좌표
  없는 `PhotoLocation`을 항상 유지하고 앵커(이상치 판정 기준)에는 관여하지 않는 동작.

**참고 — 이 태스크에서 변경하지 않는 것**: `TakeoutIngestService::extractCandidates()`,
`PlainZipIngestService::extractCandidates()`는 이미 `$parsed === null || $parsed->takenAt === null`
조건만으로 candidate를 채택하고 있어, Task 1의 파서 변경만으로 좌표 없는 사진도 자동으로
candidate에 포함된다. 이 두 파일은 **코드 수정이 필요 없다** — 테스트만 추가/수정한다.

- [ ] **Step 1: 실패하는 테스트 작성**

`tests/unit/TakeoutIngestServiceTest.php`의 `testSkipsPhotosWithoutGpsInJson`
메서드(135-146번째 줄) 전체를 다음으로 교체한다:

```php
    public function testIncludesPhotosWithoutGpsInJsonWhenTimestampPresent(): void
    {
        // GPS 없이 촬영 시각만 있는 사진도 이제 후보에 포함된다(시간표 노출용) —
        // 위치만 null 로 비워둔 PhotoLocation 이 나온다.
        $zipPath = $this->makeZip([
            'photo1.jpg' => $this->jpegBytes(),
            'photo1.jpg.json' => (string) json_encode(['photoTakenTime' => ['timestamp' => '1563490529']]),
        ]);

        $service = new TakeoutIngestService(new TakeoutMetadataParser());
        $result = $service->ingest($zipPath, 1);

        $this->assertCount(1, $result['locations']);
        $this->assertNull($result['locations'][0]->lat);
        $this->assertNull($result['locations'][0]->lng);
        $this->assertSame('photo1.jpg', $result['locations'][0]->mediaItemId);
    }
```

`testNotCappedWhenSomeCandidatesLackGpsButUnderMaxItems` 메서드(166-183번째 줄) 전체를
다음으로 교체한다(GPS 없는 사진은 이제 살아남으므로, "상한 오판정 방지"를 검증하려면
"촬영 시각 자체가 없는 사진"으로 시나리오를 바꿔야 한다):

```php
    public function testNotCappedWhenSomeCandidatesLackTimestampButUnderMaxItems(): void
    {
        // 상한(200장)보다 훨씬 적은 2개 중 1개는 촬영 시각조차 없어 제외되는 경우 —
        // "상한까지만 처리됨"이 아니라 단순히 시각을 못 찾은 것으로 구분돼야 한다.
        // (GPS 없는 사진은 이제 시각만 있으면 포함되므로, 상한 오판정 시나리오는
        // "시각 자체가 없는 사진"으로 재현해야 한다.)
        $zipPath = $this->makeZip([
            'photo1.jpg' => $this->jpegBytes(),
            'photo1.jpg.json' => $this->geoJson(37.5665, 126.9780, '1563490529'),
            'photo2.jpg' => $this->jpegBytes(),
            'photo2.jpg.json' => (string) json_encode(['geoData' => ['latitude' => 37.5, 'longitude' => 127.0]]), // 시각 없음
        ]);

        $service = new TakeoutIngestService(new TakeoutMetadataParser());
        $result = $service->ingest($zipPath, 1);

        $this->assertCount(1, $result['locations']);
        $this->assertSame(2, $result['totalCandidates']);
        $this->assertFalse($result['capped']);
    }
```

같은 파일의 `testSingleGlitchPointIsStillDropped` 메서드(296-314번째 줄) 바로 다음에
다음 테스트를 추가한다:

```php
    public function testGpsLessPhotoDoesNotBreakOutlierAnchoring(): void
    {
        // 서울 → GPS 없는 사진(시각만) → 부산(서울 기준 비현실적 속도) 순서.
        // GPS 없는 사진은 항상 유지되고, 부산 사진의 이상치 판정은 여전히 서울을 기준으로 한다.
        $zipPath = $this->makeZip([
            'seoul.jpg' => $this->jpegBytes(),
            'seoul.jpg.json' => $this->geoJson(37.5665, 126.9780, '1000000000'),
            'nogeo.jpg' => $this->jpegBytes(),
            'nogeo.jpg.json' => (string) json_encode(['photoTakenTime' => ['timestamp' => '1000000100']]),
            'busan.jpg' => $this->jpegBytes(),
            'busan.jpg.json' => $this->geoJson(35.1796, 129.0756, '1000000300'),
        ]);

        $service = new TakeoutIngestService(new TakeoutMetadataParser());
        $result = $service->ingest($zipPath, 1);

        $ids = array_map(static fn ($l) => $l->mediaItemId, $result['locations']);
        $this->assertSame(['seoul.jpg', 'nogeo.jpg'], $ids); // busan 은 여전히 이상치로 제외.
    }
```

`tests/unit/PlainZipIngestServiceTest.php`의 `testSkipsPhotosWithoutTakenTime`
메서드(152-165번째 줄) 바로 다음에 다음 테스트를 추가한다:

```php
    public function testIncludesPhotosWithoutGpsWhenTakenTimePresent(): void
    {
        $zipPath = $this->makeZip([
            'a.jpg' => $this->jpegBytes(),
            'b.jpg' => $this->jpegBytes(),
        ]);

        $reader = $this->fakeReader([
            'a.jpg' => $this->exifFor(37.5, 127.0, '2019:07:18 09:00:00'),
            'b.jpg' => ['DateTimeOriginal' => '2019:07:18 10:00:00'], // GPS 없음, 시각만 있음
        ]);

        $service = new PlainZipIngestService(new PhotoExifParser(), $reader);
        $result = $service->ingest($zipPath, 1);

        $this->assertCount(2, $result['locations']);
        $noGps = array_values(array_filter($result['locations'], static fn ($l) => $l->mediaItemId === 'b.jpg'));
        $this->assertCount(1, $noGps);
        $this->assertNull($noGps[0]->lat);
        $this->assertNull($noGps[0]->lng);
    }
```

- [ ] **Step 2: 테스트가 실패하는지 확인**

Run: `vendor/bin/phpunit tests/unit/TakeoutIngestServiceTest.php tests/unit/PlainZipIngestServiceTest.php`
Expected: FAIL — `testIncludesPhotosWithoutGpsInJsonWhenTimestampPresent`은
`assertCount(1, $result['locations'])`에서 실패(현재는 GPS 없으면 candidate 자체가 안
만들어져 `locations`가 빈 배열), `testGpsLessPhotoDoesNotBreakOutlierAnchoring`과
`testIncludesPhotosWithoutGpsWhenTakenTimePresent`도 동일한 이유로 실패.
`testNotCappedWhenSomeCandidatesLackTimestampButUnderMaxItems`는 이미 현재 코드로도
통과할 수 있음(시각 없으면 여전히 스킵되므로) — 이 테스트는 RED 확인 대상이 아니라
Step 4에서 회귀 확인용으로 그대로 통과해야 한다.

- [ ] **Step 3: AbstractZipIngestService::filterOutliers() 수정**

`app/Services/AbstractZipIngestService.php`의 `filterOutliers()` 메서드
(155-199번째 줄, 문서 주석 포함)를 다음으로 교체한다:

```php
    /**
     * 직전 유효 지점 대비 비현실적 이동 속도(기본 200km/h 초과)인 지점을 제외한다.
     *
     * 단, 걸러진 지점 바로 다음 지점이 그 걸러진 지점과 서로 정합하면(같은 지역)
     * 비행기·KTX 등 실제 고속 이동의 도착지로 보고 둘 다 살려 앵커를 옮긴다 —
     * 앵커 고착으로 도착지 동선이 통째로 잘리는 것을 방지한다.
     *
     * 좌표 없는 지점(GPS 없이 촬영 시각만 있는 사진)은 속도 계산 자체가 불가능하므로
     * 항상 유지하고, 앵커(previous)·직전 걸러진 지점(dropped) 갱신에는 관여하지 않는다
     * — 좌표 없는 지점을 몇 개 건너뛰어도 그다음 좌표 있는 지점은 그 이전의 마지막
     * 좌표 있는 지점을 기준으로 이상치 판정을 받는다.
     *
     * @param list<PhotoLocation> $locations
     *
     * @return list<PhotoLocation>
     */
    protected function filterOutliers(array $locations): array
    {
        if (count($locations) <= 1) {
            return $locations;
        }

        usort($locations, static fn (PhotoLocation $a, PhotoLocation $b): int => strcmp($a->takenAt, $b->takenAt));

        $kept = [];
        $previous = null; // 마지막으로 채택된, 좌표 있는 지점(이상치 판정 기준 앵커).
        $dropped = null; // 직전에 걸러진, 좌표 있는 지점 — 후속 지점이 확인해 주면 복권된다.

        foreach ($locations as $current) {
            if ($current->lat === null || $current->lng === null) {
                $kept[] = $current;
                continue;
            }

            if ($previous === null || $this->isReachable($previous, $current)) {
                $kept[] = $current;
                $previous = $current;
                $dropped = null;
                continue;
            }

            if ($dropped !== null && $this->isReachable($dropped, $current)) {
                // 연속 두 지점이 서로 일치 → 실제 이동으로 재인정하고 도착지로 재앵커.
                $kept[] = $dropped;
                $kept[] = $current;
                $previous = $current;
                $dropped = null;
                continue;
            }

            $dropped = $current;
        }

        return $kept;
    }
```

`isReachable()` 메서드(현재 201-211번째 줄)는 변경하지 않는다(호출부가 이미 좌표 있는
두 지점만 넘기도록 위에서 방어했으므로 그대로 안전하다).

- [ ] **Step 4: 테스트 통과 확인**

Run: `vendor/bin/phpunit tests/unit/TakeoutIngestServiceTest.php tests/unit/PlainZipIngestServiceTest.php`
Expected: `OK (...)` — 두 파일 전체(기존 이상치 필터 테스트 3종 회귀 없이 통과 + 신규 3종
통과) 통과.

- [ ] **Step 5: 정적 분석 통과 확인**

Run: `composer analyse`
Expected: `[OK] No errors`

- [ ] **Step 6: 커밋**

```bash
git add app/Services/AbstractZipIngestService.php \
  tests/unit/TakeoutIngestServiceTest.php tests/unit/PlainZipIngestServiceTest.php
git commit -m "$(cat <<'EOF'
✨ feat: 이상치 필터가 GPS 없는 사진을 항상 유지하도록 변경 (#29)

EOF
)"
```

---

### Task 3: RouteVisualizationService — 지도에서 좌표 없는 사진 제외

**Files:**
- Modify: `app/Services/RouteVisualizationService.php:49-87`(`buildForUser()`)
- Test: `tests/unit/RouteVisualizationServiceTest.php`

**Interfaces:**
- Consumes: `App\Models\PhotoLocationModel::findByUserOrdered()`가 반환하는 행(이미
  `lat`/`lng`가 `null`일 수 있는 컬럼).
- Produces: `RouteVisualizationService::buildForUser()`가 `lat`이나 `lng`가 `null`인 행을
  제외한 결과를 반환(다른 태스크가 이 인터페이스를 소비하지 않는다 — 독립 태스크).

- [ ] **Step 1: 실패하는 테스트 작성**

`tests/unit/RouteVisualizationServiceTest.php`의 `testReturnsEmptyDatesWhenNoLocations`
메서드 바로 다음에 다음 테스트를 추가한다:

```php
    public function testExcludesPhotosWithoutCoordinatesFromMap(): void
    {
        $service = $this->serviceWithRows([
            ['id' => 1, 'source_item_id' => 'm1', 'lat' => '37.5000000', 'lng' => '127.0000000', 'taken_at' => '2024-03-15 09:00:00'],
            ['id' => 2, 'source_item_id' => 'm2', 'lat' => null, 'lng' => null, 'taken_at' => '2024-03-15 09:30:00'],
        ]);

        $result = $service->buildForUser(1);

        $this->assertCount(1, $result['dates'][0]['points']);
        $this->assertSame('m1', $result['dates'][0]['points'][0]['media_item_id']);
    }
```

- [ ] **Step 2: 테스트가 실패하는지 확인**

Run: `vendor/bin/phpunit --filter testExcludesPhotosWithoutCoordinatesFromMap tests/unit/RouteVisualizationServiceTest.php`
Expected: FAIL — `assertCount(1, ...)`가 실패(현재는 `(float) ($row['lat'] ?? 0)`로 좌표
없는 행도 `(0, 0)` 지점으로 포함되어 2개가 됨).

- [ ] **Step 3: buildForUser() 에 좌표 없는 행 스킵 추가**

`app/Services/RouteVisualizationService.php`의 `buildForUser()` 메서드(49-87번째 줄) 중
`foreach ($rows as $row) {` 블록 내부, `$takenAt === ''` 스킵 바로 다음에 다음 코드를
추가한다:

```php
    public function buildForUser(int $userId): array
    {
        $rows = $this->model->findByUserOrdered($userId);

        // taken_at 오름차순으로 조회되므로 날짜별 그룹·그룹 내 순서가 자연히 유지된다.
        $grouped = [];
        foreach ($rows as $row) {
            $takenAt = (string) ($row['taken_at'] ?? '');
            if ($takenAt === '') {
                continue;
            }

            // 좌표 없는 사진(GPS 없이 촬영 시각만 있는 사진)은 지도에 표시할 수 없어
            // 제외한다 — 시간표(TimelineService)에서만 노출한다.
            if ($row['lat'] === null || $row['lng'] === null) {
                continue;
            }

            // 저장은 UTC — 표시·날짜 그룹핑은 한국시간(KST) 기준.
            $takenAt = TimeConverter::utcToKst($takenAt);

            $date = substr($takenAt, 0, 10);
            $grouped[$date][] = [
                'lat' => (float) ($row['lat'] ?? 0),
                'lng' => (float) ($row['lng'] ?? 0),
                'taken_at' => $takenAt,
                'media_item_id' => (string) ($row['source_item_id'] ?? ''),
                'thumbnail_url' => empty($row['thumbnail_path']) ? null : '/thumbnails/' . (int) ($row['id'] ?? 0),
            ];
        }

        $dates = [];
        $index = 0;
        foreach ($grouped as $date => $points) {
            $dates[] = [
                'date' => $date,
                'color' => self::PALETTE[$index % count(self::PALETTE)],
                'points' => $points,
                'clusters' => $this->clusterByProximity($points),
            ];
            $index++;
        }

        return ['dates' => $dates];
    }
```

- [ ] **Step 4: 테스트 통과 확인**

Run: `vendor/bin/phpunit tests/unit/RouteVisualizationServiceTest.php`
Expected: `OK (12 tests, ...)` — 기존 11개 + 신규 1개.

- [ ] **Step 5: 정적 분석 통과 확인**

Run: `composer analyse`
Expected: `[OK] No errors`

- [ ] **Step 6: 커밋**

```bash
git add app/Services/RouteVisualizationService.php tests/unit/RouteVisualizationServiceTest.php
git commit -m "$(cat <<'EOF'
✨ feat: 지도에서 GPS 없는 사진 제외 (#29)

EOF
)"
```

---

### Task 4: TimelineService — 좌표 없는 사진을 직전 세그먼트에 편입

**Files:**
- Modify: `app/Services/TimelineService.php:101-148`(`segmentByPlace()`)
- Test: `tests/unit/TimelineServiceTest.php`

**Interfaces:**
- Consumes: `App\Models\PhotoLocationModel::findByUserBetween()`가 반환하는 행(`lat`/`lng`가
  `null`일 수 있음).
- Produces: `TimelineService::buildForDate()`가 반환하는 `slots[].lat`/`slots[].lng`는
  이미 기존 PHPDoc(`float|null`)에 나타나 있던 대로 계속 `float|null`이지만, 이제
  **사진이 있는 세그먼트**도 `lat: null`일 수 있다(기존에는 "사진 없는 메모 전용 슬롯"만
  `lat: null`이었음).

- [ ] **Step 1: 실패하는 테스트 작성**

`tests/unit/TimelineServiceTest.php`의 `testQueriesUtcRangeCoveringKstDay` 메서드
바로 다음(파일 끝 `}` 직전)에 다음 3개 테스트를 추가한다:

```php
    public function testPhotoWithoutCoordinatesJoinsPrecedingSegment(): void
    {
        $service = $this->service([
            // UTC 09:00 = KST 18:00, 좌표 있음.
            ['id' => 1, 'source_item_id' => 'm1', 'lat' => '37.5000000', 'lng' => '127.0000000', 'thumbnail_path' => null, 'taken_at' => '2024-03-15 09:00:00'],
            // UTC 09:05 = KST 18:05, GPS 없음 — 직전 세그먼트(18:00)에 편입돼야 한다.
            ['id' => 2, 'source_item_id' => 'm2', 'lat' => null, 'lng' => null, 'thumbnail_path' => null, 'taken_at' => '2024-03-15 09:05:00'],
        ]);

        $result = $service->buildForDate(1, '2024-03-15');

        $this->assertCount(1, $result['slots']);
        $this->assertCount(2, $result['slots'][0]['photos']);
        $this->assertEqualsWithDelta(37.5, $result['slots'][0]['lat'], 0.0001);
    }

    public function testFirstPhotoOfDayWithoutCoordinatesOpensNullLocationSegment(): void
    {
        $service = $this->service([
            ['id' => 1, 'source_item_id' => 'm1', 'lat' => null, 'lng' => null, 'thumbnail_path' => null, 'taken_at' => '2024-03-15 09:00:00'],
        ]);

        $result = $service->buildForDate(1, '2024-03-15');

        $this->assertCount(1, $result['slots']);
        $this->assertNull($result['slots'][0]['lat']);
        $this->assertCount(1, $result['slots'][0]['photos']);
    }

    public function testPhotoWithCoordinatesAfterNullLocationSegmentStartsNewSegment(): void
    {
        $service = $this->service([
            ['id' => 1, 'source_item_id' => 'm1', 'lat' => null, 'lng' => null, 'thumbnail_path' => null, 'taken_at' => '2024-03-15 09:00:00'],
            ['id' => 2, 'source_item_id' => 'm2', 'lat' => '37.5000000', 'lng' => '127.0000000', 'thumbnail_path' => null, 'taken_at' => '2024-03-15 09:05:00'],
        ]);

        $result = $service->buildForDate(1, '2024-03-15');

        $this->assertCount(2, $result['slots']);
        $this->assertNull($result['slots'][0]['lat']);
        $this->assertEqualsWithDelta(37.5, $result['slots'][1]['lat'], 0.0001);
    }
```

- [ ] **Step 2: 테스트가 실패하는지 확인**

Run: `vendor/bin/phpunit tests/unit/TimelineServiceTest.php`
Expected: FAIL — 3개 신규 테스트가 실패(현재 `segmentByPlace()`는 `(float) ($row['lat'] ?? 0)`
로 좌표 없는 사진을 `(0, 0)` 좌표로 취급해 "30m 넘게 떨어진 다른 장소"로 판정하므로
세그먼트가 예상과 다르게 나뉜다). 기존 4개 테스트는 그대로 통과해야 한다.

- [ ] **Step 3: segmentByPlace() 수정**

`app/Services/TimelineService.php`의 `segmentByPlace()` 메서드(91-148번째 줄, 문서 주석
포함)를 다음으로 교체한다:

```php
    /**
     * 시간순 좌표를 "장소가 바뀌는 지점"에서 잘라 세그먼트로 묶는다.
     *
     * 세그먼트 기준 좌표는 첫 지점 좌표(드리프트 방지), 슬롯 키는 첫 사진의
     * KST 시각("HH:MM")이다.
     *
     * 좌표 없는 사진(GPS 없이 촬영 시각만 있는 사진)은 장소 비교 자체가 불가능하므로,
     * 직전에 열린 세그먼트가 있으면(좌표 유무 무관) 그대로 편입하고, 없으면(하루의 첫
     * 사진부터 좌표가 없는 경우) `lat: null, lng: null` 인 새 세그먼트를 연다. 좌표
     * 없는 세그먼트 다음에 좌표 있는 사진이 오면 "같은 장소"로 비교할 기준이 없으므로
     * 무조건 새 세그먼트를 연다.
     *
     * @param list<array<string, mixed>> $rows taken_at(UTC) 오름차순 좌표 행
     *
     * @return list<array{slot: string, lat: float|null, lng: float|null, photos: list<array{media_item_id: string, taken_at: string, thumbnail_url: string|null}>}>
     */
    private function segmentByPlace(array $rows): array
    {
        $segments = [];
        $current = null;

        foreach ($rows as $row) {
            $takenAt = (string) ($row['taken_at'] ?? '');
            if ($takenAt === '') {
                continue;
            }

            // 표시·슬롯 키는 한국시간(KST) 기준.
            $takenAt = TimeConverter::utcToKst($takenAt);
            $rawLat = $row['lat'] ?? null;
            $rawLng = $row['lng'] ?? null;
            $lat = $rawLat === null ? null : (float) $rawLat;
            $lng = $rawLng === null ? null : (float) $rawLng;

            $photo = [
                'media_item_id' => (string) ($row['source_item_id'] ?? ''),
                'taken_at' => $takenAt,
                'thumbnail_url' => empty($row['thumbnail_path']) ? null : '/thumbnails/' . (int) ($row['id'] ?? 0),
            ];

            $isSamePlace = $current !== null
                && $current['lat'] !== null && $current['lng'] !== null
                && $lat !== null && $lng !== null
                && GeoDistanceCalculator::kilometers($current['lat'], $current['lng'], $lat, $lng) <= self::SEGMENT_RADIUS_KM;

            $isNoLocationContinuation = $current !== null && $lat === null && $lng === null;

            if ($isSamePlace || $isNoLocationContinuation) {
                $current['photos'][] = $photo;
                continue;
            }

            if ($current !== null) {
                $segments[] = $current;
            }

            $current = [
                'slot' => substr($takenAt, 11, 5),
                'lat' => $lat,
                'lng' => $lng,
                'photos' => [$photo],
            ];
        }

        if ($current !== null) {
            $segments[] = $current;
        }

        return $segments;
    }
```

- [ ] **Step 4: 테스트 통과 확인**

Run: `vendor/bin/phpunit tests/unit/TimelineServiceTest.php`
Expected: `OK (8 tests, ...)` — 기존 5개 + 신규 3개.

- [ ] **Step 5: 정적 분석 통과 확인**

Run: `composer analyse`
Expected: `[OK] No errors`

- [ ] **Step 6: 커밋**

```bash
git add app/Services/TimelineService.php tests/unit/TimelineServiceTest.php
git commit -m "$(cat <<'EOF'
✨ feat: 시간표에서 GPS 없는 사진을 직전 세그먼트에 편입 (#29)

EOF
)"
```

---

### Task 5: TripStatsService — 좌표 없는 행을 통계 계산에서 제외

**Files:**
- Modify: `app/Services/TripStatsService.php:45-59`(`buildStatsFromRows()`)
- Test: `tests/unit/TripStatsServiceTest.php`

**Interfaces:**
- Consumes: `App\Models\PhotoLocationModel::findByUserBetween()`가 반환하는 행(`lat`/`lng`가
  `null`일 수 있음).
- Produces: `TripStatsService::buildStatsFromRows()`가 좌표 없는 행을 이동거리 누적과
  `PointClusterer::countClusters()` 양쪽에서 제외한 결과를 반환(공개 시그니처는 변경 없음).

- [ ] **Step 1: 실패하는 테스트 작성**

`tests/unit/TripStatsServiceTest.php` 파일 끝(클래스 닫는 중괄호 직전)에 다음 테스트를
추가한다:

```php
    public function testExcludesRowsWithoutCoordinatesFromDistanceAndSpotCount(): void
    {
        $service = new TripStatsService($this->createMock(PhotoLocationModel::class));

        $p1 = ['lat' => 37.5665, 'lng' => 126.9780];
        $p2 = ['lat' => 37.4979, 'lng' => 127.0276];

        $stats = $service->buildStatsFromRows([
            ['lat' => (string) $p1['lat'], 'lng' => (string) $p1['lng'], 'taken_at' => '2024-03-15 01:00:00'],
            ['lat' => null, 'lng' => null, 'taken_at' => '2024-03-15 01:30:00'],
            ['lat' => (string) $p2['lat'], 'lng' => (string) $p2['lng'], 'taken_at' => '2024-03-15 02:00:00'],
        ]);

        $expectedDistance = GeoDistanceCalculator::kilometers($p1['lat'], $p1['lng'], $p2['lat'], $p2['lng']);

        $this->assertEqualsWithDelta($expectedDistance, $stats['distance_km'], 0.0001);
        $this->assertSame(2, $stats['spot_count']);
    }
```

- [ ] **Step 2: 테스트가 실패하는지 확인**

Run: `vendor/bin/phpunit --filter testExcludesRowsWithoutCoordinatesFromDistanceAndSpotCount tests/unit/TripStatsServiceTest.php`
Expected: FAIL — `assertSame(2, $stats['spot_count'])`가 실패(현재는 좌표 없는 행이
`(0, 0)` 지점으로 취급돼 별도 클러스터로 잡히고 거리 계산도 왜곡됨).

- [ ] **Step 3: buildStatsFromRows() 수정**

`app/Services/TripStatsService.php`의 `buildStatsFromRows()` 메서드(45-59번째 줄)를
다음으로 교체한다:

```php
    public function buildStatsFromRows(array $rows): array
    {
        $points = [];
        foreach ($rows as $row) {
            $lat = $row['lat'] ?? null;
            $lng = $row['lng'] ?? null;
            if ($lat === null || $lng === null) {
                continue; // GPS 없이 촬영 시각만 있는 사진은 거리·클러스터 계산에서 제외한다.
            }

            $points[] = ['lat' => (float) $lat, 'lng' => (float) $lng];
        }

        $distanceKm = 0.0;
        for ($i = 1, $count = count($points); $i < $count; $i++) {
            $distanceKm += GeoDistanceCalculator::kilometers(
                $points[$i - 1]['lat'],
                $points[$i - 1]['lng'],
                $points[$i]['lat'],
                $points[$i]['lng'],
            );
        }

        return [
            'distance_km' => $distanceKm,
            'spot_count' => PointClusterer::countClusters($points),
        ];
    }
```

- [ ] **Step 4: 테스트 통과 확인**

Run: `vendor/bin/phpunit tests/unit/TripStatsServiceTest.php`
Expected: `OK (5 tests, ...)` — 기존 4개 + 신규 1개.

- [ ] **Step 5: 정적 분석 통과 확인**

Run: `composer analyse`
Expected: `[OK] No errors`

- [ ] **Step 6: 커밋**

```bash
git add app/Services/TripStatsService.php tests/unit/TripStatsServiceTest.php
git commit -m "$(cat <<'EOF'
✨ feat: 여행 통계 계산에서 GPS 없는 사진 제외 (#29)

EOF
)"
```

---

### Task 6: 프론트엔드 — "위치 정보 없음" 표시 + 브라우저 실측 검증

**Files:**
- Modify: `app/Views/trip-detail.php`(`buildDayTimelineSlot()` 내 POI 표시 블록)
- Modify: `app/Views/map.php`(`buildSlotRow()` 내 POI 표시 블록)

**Interfaces:**
- Consumes: Task 4가 만든 `TimelineService`의 `slots[].lat`/`slots[].lng`(`null`일 수 있음,
  이미 기존 JSON 응답 형태 그대로 — 새 필드 없음).

- [ ] **Step 1: trip-detail.php 수정**

`app/Views/trip-detail.php`의 `buildDayTimelineSlot()` 함수 안, 다음 블록을 찾는다:

```javascript
                if (slot.lat !== null && slot.lng !== null) {
                    var poiEl = document.createElement('div');
                    poiEl.className = 'timeline-poi';
                    contentEl.appendChild(poiEl);
                    poiTasks.push({ el: poiEl, lat: slot.lat, lng: slot.lng });
                }
```

이를 다음으로 교체한다(좌표 없고 사진이 있는 세그먼트에는 "위치 정보 없음"을 표시하되,
사진도 없는 순수 메모 슬롯에는 기존처럼 아무것도 표시하지 않는다):

```javascript
                if (slot.lat !== null && slot.lng !== null) {
                    var poiEl = document.createElement('div');
                    poiEl.className = 'timeline-poi';
                    contentEl.appendChild(poiEl);
                    poiTasks.push({ el: poiEl, lat: slot.lat, lng: slot.lng });
                } else if (slot.photos.length) {
                    var noLocationEl = document.createElement('div');
                    noLocationEl.className = 'timeline-poi';
                    noLocationEl.textContent = '위치 정보 없음';
                    contentEl.appendChild(noLocationEl);
                }
```

- [ ] **Step 2: map.php 수정**

`app/Views/map.php`의 `buildSlotRow()` 함수 안, 다음 블록을 찾는다:

```javascript
                // 주변 업장 정보(식당·카페 등) 자리 — 실제 조회는 사진 로드 후 순차 실행된다.
                if (slotEntry.lat !== null && slotEntry.lng !== null) {
                    var poiEl = document.createElement('div');
                    poiEl.className = 'timeline-poi';
                    contentEl.appendChild(poiEl);
                    poiTasks.push({ el: poiEl, lat: slotEntry.lat, lng: slotEntry.lng });
                }
```

이를 다음으로 교체한다:

```javascript
                // 주변 업장 정보(식당·카페 등) 자리 — 실제 조회는 사진 로드 후 순차 실행된다.
                if (slotEntry.lat !== null && slotEntry.lng !== null) {
                    var poiEl = document.createElement('div');
                    poiEl.className = 'timeline-poi';
                    contentEl.appendChild(poiEl);
                    poiTasks.push({ el: poiEl, lat: slotEntry.lat, lng: slotEntry.lng });
                } else if (slotEntry.photos.length) {
                    var noLocationEl = document.createElement('div');
                    noLocationEl.className = 'timeline-poi';
                    noLocationEl.textContent = '위치 정보 없음';
                    contentEl.appendChild(noLocationEl);
                }
```

- [ ] **Step 3: composer ci 전체 검증**

Run: `composer ci`
Expected: CS Fixer 통과, `[OK] No errors`(PHPStan), PHPUnit 전체 통과 — 기존 264개 +
Task 1(신규 2: TakeoutMetadataParserTest +1, PhotoExifParserTest +1) +
Task 2(신규 2: TakeoutIngestServiceTest +1, PlainZipIngestServiceTest +1) +
Task 3(신규 1) + Task 4(신규 3) + Task 5(신규 1) = 273개(이름·검증 로직만 바뀐 기존
테스트는 개수에 영향 없음).

- [ ] **Step 4: 브라우저 실측 검증**

임시 SQLite 환경을 띄운다(기존 세션에서 반복 사용한 패턴 — `.env`를 백업 후 Python으로
원본 `app.baseURL`·DB 설정 줄을 in-place 치환, 완료 후 반드시 원복):

```bash
cp .env .env.bak-no-gps-verify
python3 - <<'EOF'
p = '.env'
s = open(p).read()
s = s.replace("app.baseURL = 'http://localhost:8080/'", "app.baseURL = 'http://localhost:8299/'")
open(p, 'w').write(s)
EOF
cat >> .env <<'EOF'

# TEMP no-gps-verify
database.default.database = dev-no-gps-verify.db
database.default.DBDriver = SQLite3
database.default.DBPrefix =
EOF
php spark migrate --all
```

임시 spark 커맨드(`app/Commands/SeedNoGpsVerify.php`, 검증 후 삭제)로 사용자 1명 +
같은 날짜에 좌표 있는 사진 1장(예: 09:00, 서울시청)과 좌표 없는 사진 1장(예: 09:30,
`PhotoLocationModel::saveMany()`에 `lat`/`lng`를 `null`로 저장)을 시딩한다. 서버를
`php -S localhost:8299 -t public`로 띄우고 세션 파일에 PHP 네이티브 포맷(`user_id|i:1;`)
으로 `user_id`를 주입한 뒤, `/map`으로 이동해 해당 날짜의 시간표 레이어를 연다.

다음을 확인한다:
1. 좌표 있는 사진의 세그먼트에는 기존처럼 주변 업장 정보 영역이 뜬다(`GET /timeline/poi`
   요청이 나간다).
2. 좌표 없는 사진의 세그먼트에는 "위치 정보 없음" 텍스트가 표시되고, 그 세그먼트에 대한
   `GET /timeline/poi` 요청이 나가지 않는다(`read_network_requests`로 확인).
3. 사진 개수(`사진 N장`)는 좌표 유무와 무관하게 정확히 표시된다.
4. `/trips/{id}` 여행 상세 페이지의 인라인 시간표에서도 동일하게 동작한다(같은 날짜를
   포함하는 여행을 하나 만들어 확인).

검증이 끝나면 임시 커맨드와 환경을 원복한다:

```bash
rm -f app/Commands/SeedNoGpsVerify.php
mv .env.bak-no-gps-verify .env
rm -f writable/dev-no-gps-verify.db
rm -f writable/session/ci_session*
git status --short
```

`git status --short` 결과가 이번 작업 커밋 대상 파일 외에 다른 변경을 포함하지 않는지
확인한다.

- [ ] **Step 5: 커밋**

```bash
git add app/Views/trip-detail.php app/Views/map.php
git commit -m "$(cat <<'EOF'
✨ feat: 위치 정보 없는 시간표 항목에 안내 문구 표시 (#29)

EOF
)"
```
