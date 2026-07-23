# 여행 발자국 지도 구현 계획

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 사진 좌표를 지역 단위(국내 시·도 + 해외 국가)로 판별·저장하고, 방문 지역을 색칠한 발자국 지도 페이지(`/footprint`)를 제공한다.

**Architecture:** 경계 GeoJSON(Natural Earth 110m 세계 + KOSTAT 2013 시·도 간략본)을 `public/assets/geo/`에 번들. `RegionResolver`(순수 PHP ray-casting PIP)가 업로드 시 좌표를 판별해 `photo_locations.country_code/region_code`에 저장, 기존 행은 `spark region:backfill`로 일괄 처리. 페이지는 SQL GROUP BY 집계(`FootprintService`) + Leaflet choropleth.

**Tech Stack:** PHP 8.2+/CI4, MySQL, Leaflet 1.9.4, 번들 GeoJSON. 외부 API·신규 라이브러리 없음.

## Global Constraints

- 설계 문서: `docs/superpowers/specs/2026-07-23-footprint-map-design.md`.
- 작업 브랜치 `feature/footprint-map`(origin/dev 기준), PR 없이 dev 직접 머지.
- 모든 경로는 `Iter/` 프로젝트 루트 기준. PHP는 `declare(strict_types=1)`·PSR-12·한국어 주석·타입 완전 선언.
- `region_code`는 ISO 3166-2:KR(현행 — 강원 `KR-51`, 전북 `KR-56`), `country_code`는 ISO 3166-1 alpha-2.
- 한국 좌표는 **시·도 GeoJSON을 먼저** 판별한다(110m 세계 경계는 해안이 거칠어 KR 해안 사진이 바다로 새는 것을 방지). 시·도 미매치 시에만 세계 경계 판별.
- 판별 불가(바다 등)는 country/region 둘 다 null — 통계 제외. `RegionResolver`는 인제스트·백필에서만 로드(발자국 페이지는 SQL만).
- 검증: `composer ci`(CS Fixer→PHPStan→PHPUnit) 통과 필수. RegionResolver·FootprintService는 PHPUnit 단위 테스트 필수.
- 경계 원본 파일은 컨트롤러 세션의 스크래치패드에 이미 다운로드돼 있다(Task 0 참조). 라이선스: Natural Earth = 퍼블릭 도메인, KOSTAT 2013(southkorea-maps) = "Free to share or remix".

---

### Task 0: 브랜치 생성 + 경계 GeoJSON 번들

**Files:**
- Create: `public/assets/geo/world-countries.json`
- Create: `public/assets/geo/kr-sido.json`
- Create: `public/assets/geo/README.md`

**Interfaces:**
- Produces: 두 GeoJSON 의 feature.properties 가 `{iso: string|null, name: string}` 로 통일됨.
  세계는 iso=ISO 3166-1 alpha-2(판별 불가 국가는 null), 시·도는 iso=ISO 3166-2:KR.
  이후 모든 태스크(PHP 판별·JS 렌더)가 `properties.iso`/`properties.name` 만 사용한다.

- [ ] **Step 1: 브랜치 생성**

```bash
cd /Users/jongwonbyun/claude-works/varius/Iter
git checkout dev && git pull origin dev && git checkout -b feature/footprint-map
```

- [ ] **Step 2: 속성 슬림·ISO 부여 스크립트 실행**

원본(컨트롤러가 미리 다운로드·검증함):
- 세계: `/private/tmp/claude-501/-Users-jongwonbyun-claude-works-varius-Iter/a0d2c77b-d90e-488f-92af-0cf6b7d1519a/scratchpad/world110.geojson` (819KB, 177 features, ISO_A2/ISO_A2_EH)
- 시·도: `/private/tmp/claude-501/-Users-jongwonbyun-claude-works-varius-Iter/a0d2c77b-d90e-488f-92af-0cf6b7d1519a/scratchpad/kr-sido-simple.geojson` (143KB, 17 features, KOSTAT code/name)

```bash
mkdir -p public/assets/geo
python3 - <<'PYEOF'
import json

SCRATCH = '/private/tmp/claude-501/-Users-jongwonbyun-claude-works-varius-Iter/a0d2c77b-d90e-488f-92af-0cf6b7d1519a/scratchpad'

# 세계: ISO_A2 가 -99 면 ISO_A2_EH 로 보완(노르웨이·프랑스 등), 둘 다 -99 면 null
w = json.load(open(f'{SCRATCH}/world110.geojson'))
for f in w['features']:
    p = f['properties']
    a2, eh = p.get('ISO_A2', '-99'), p.get('ISO_A2_EH', '-99')
    iso = a2 if a2 != '-99' else (eh if eh != '-99' else None)
    f['properties'] = {'iso': iso, 'name': p.get('NAME', '')}
json.dump(w, open('public/assets/geo/world-countries.json', 'w'), ensure_ascii=False, separators=(',', ':'))

# 시·도: KOSTAT 코드 → 현행 ISO 3166-2:KR
KOSTAT_TO_ISO = {
    '11': 'KR-11', '21': 'KR-26', '22': 'KR-27', '23': 'KR-28', '24': 'KR-29',
    '25': 'KR-30', '26': 'KR-31', '29': 'KR-50', '31': 'KR-41', '32': 'KR-51',
    '33': 'KR-43', '34': 'KR-44', '35': 'KR-56', '36': 'KR-46', '37': 'KR-47',
    '38': 'KR-48', '39': 'KR-49',
}
# 2013 데이터의 옛 이름을 현행 명칭으로 갱신
RENAME = {'강원도': '강원특별자치도', '전라북도': '전북특별자치도'}
s = json.load(open(f'{SCRATCH}/kr-sido-simple.geojson'))
assert len(s['features']) == 17
for f in s['features']:
    p = f['properties']
    name = RENAME.get(p['name'], p['name'])
    f['properties'] = {'iso': KOSTAT_TO_ISO[p['code']], 'name': name}
json.dump(s, open('public/assets/geo/kr-sido.json', 'w'), ensure_ascii=False, separators=(',', ':'))
print('OK')
PYEOF
ls -lh public/assets/geo/
```

Expected: `OK`, world-countries.json(~700KB 이하), kr-sido.json(~140KB).

- [ ] **Step 3: 산출물 검증**

```bash
python3 - <<'PYEOF'
import json
w = json.load(open('public/assets/geo/world-countries.json'))
s = json.load(open('public/assets/geo/kr-sido.json'))
assert len(w['features']) == 177
assert any(f['properties']['iso'] == 'KR' for f in w['features'])
assert any(f['properties']['iso'] == 'FR' for f in w['features']), 'ISO_A2_EH 보완 실패'
isos = sorted(f['properties']['iso'] for f in s['features'])
assert len(isos) == 17 and isos[0] == 'KR-11' and 'KR-51' in isos and 'KR-56' in isos
names = {f['properties']['name'] for f in s['features']}
assert '강원특별자치도' in names and '전북특별자치도' in names
print('검증 OK')
PYEOF
```

Expected: `검증 OK`

- [ ] **Step 4: 출처 README 작성** — `public/assets/geo/README.md`:

```markdown
# 경계 GeoJSON 출처

- `world-countries.json` — [Natural Earth](https://www.naturalearthdata.com/) 110m Admin 0 Countries.
  퍼블릭 도메인. 속성을 `{iso, name}` 으로 슬림했고, ISO_A2 미부여 국가는 ISO_A2_EH 로 보완했다.
- `kr-sido.json` — [southkorea/southkorea-maps](https://github.com/southkorea/southkorea-maps)
  KOSTAT 2013 시·도 간략본("Free to share or remix"). 속성을 `{iso(ISO 3166-2:KR 현행), name(현행 명칭)}` 으로 변환했다.

두 파일 모두 백엔드 지역 판별(`RegionResolver`)과 프론트 choropleth 렌더(`footprint.php`)가 공유한다.
```

- [ ] **Step 5: 커밋**

```bash
git add public/assets/geo/
git commit -m "✨ feat: 발자국 지도용 경계 GeoJSON 번들 (Natural Earth + KOSTAT 시·도)"
```

---

### Task 1: 스키마·DTO 확장

**Files:**
- Create: `app/Database/Migrations/2026-07-23-090000_AddRegionCodesToPhotoLocations.php`
- Modify: `app/Models/PhotoLocationModel.php` (allowedFields·saveMany)
- Modify: `app/Services/Ingest/PhotoLocation.php` (필드 2개 + wither)

**Interfaces:**
- Consumes: 없음
- Produces:
  - `photo_locations.country_code VARCHAR(2) NULL`, `region_code VARCHAR(8) NULL`, 인덱스 `idx_photo_locations_user_country(user_id, country_code)`
  - `PhotoLocation` 에 `public ?string $countryCode = null`, `public ?string $regionCode = null`(생성자 말미) + `withRegion(?string $countryCode, ?string $regionCode): self`
  - `saveMany()` 가 두 컬럼을 함께 INSERT

- [ ] **Step 1: 마이그레이션 작성**

```php
<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 발자국 지도용 지역 코드 컬럼 추가.
 *
 * country_code: ISO 3166-1 alpha-2 (예: KR, JP). region_code: 국내 시·도 ISO 3166-2:KR (예: KR-11).
 * 판별 불가(바다·GPS 없음)는 null — 집계에서 제외된다.
 */
final class AddRegionCodesToPhotoLocations extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('photo_locations', [
            'country_code' => ['type' => 'VARCHAR', 'constraint' => 2, 'null' => true, 'after' => 'lng'],
            'region_code' => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => true, 'after' => 'country_code'],
        ]);
        $this->forge->addKey(['user_id', 'country_code'], false, false, 'idx_photo_locations_user_country');
        $this->forge->processIndexes('photo_locations');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE photo_locations DROP INDEX idx_photo_locations_user_country');
        $this->forge->dropColumn('photo_locations', 'country_code');
        $this->forge->dropColumn('photo_locations', 'region_code');
    }
}
```

- [ ] **Step 2: PhotoLocation DTO 확장** — 생성자를 다음으로 교체(새 파라미터는 말미, 기본 null — 기존 호출부 무영향). `toArray()` 는 건드리지 않는다(직렬화 표면 유지):

```php
    public function __construct(
        public string $mediaItemId,
        public ?float $lat,
        public ?float $lng,
        public string $takenAt,
        public ?string $thumbnailPath = null,
        public ?string $countryCode = null,
        public ?string $regionCode = null,
    ) {
    }

    /**
     * 지역 판별 결과를 입힌 사본을 돌려준다(readonly 라 새 인스턴스).
     */
    public function withRegion(?string $countryCode, ?string $regionCode): self
    {
        return new self(
            $this->mediaItemId,
            $this->lat,
            $this->lng,
            $this->takenAt,
            $this->thumbnailPath,
            $countryCode,
            $regionCode,
        );
    }
```

- [ ] **Step 3: PhotoLocationModel 반영** — `$allowedFields` 에 `'country_code', 'region_code'` 추가(`'lng'` 다음), `saveMany()` 의 `$rows[] = [...]` 에 두 줄 추가:

```php
                'country_code' => $location->countryCode,
                'region_code' => $location->regionCode,
```

- [ ] **Step 4: 검증 및 커밋**

```bash
composer analyse && composer test:unit
git add app/Database/Migrations/2026-07-23-090000_AddRegionCodesToPhotoLocations.php app/Models/PhotoLocationModel.php app/Services/Ingest/PhotoLocation.php
git commit -m "✨ feat: photo_locations 에 지역 코드 컬럼·DTO 필드 추가"
```

(`composer test:unit` 스크립트가 없으면 `composer test` 사용. 로컬 DB 미설정으로 마이그레이션 실행이 불가하면 그 사실만 보고하고 진행 — 실행은 운영 배포 절차에서.)

---

### Task 2: RegionResolver 서비스 (TDD)

**Files:**
- Create: `app/Services/Region/RegionResolver.php`
- Test: `tests/Unit/RegionResolverTest.php`

**Interfaces:**
- Consumes: Task 0 의 `public/assets/geo/*.json`(properties.iso), Task 1 의 `PhotoLocation::withRegion`
- Produces:
  - `new RegionResolver(string $worldGeoJsonPath, string $sidoGeoJsonPath)`
  - `resolve(float $lat, float $lng): array{countryCode: ?string, regionCode: ?string}`
  - `enrichAll(array $locations): array` — `list<PhotoLocation>` 입출력, 좌표 없는 항목은 그대로 통과

- [ ] **Step 1: 실패하는 테스트 작성** — `tests/Unit/RegionResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ingest\PhotoLocation;
use App\Services\Region\RegionResolver;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * 좌표 → 지역 판별 검증 — 실제 번들 GeoJSON 을 사용한다(외부 의존 없음).
 */
final class RegionResolverTest extends CIUnitTestCase
{
    private RegionResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new RegionResolver(
            FCPATH . 'assets/geo/world-countries.json',
            FCPATH . 'assets/geo/kr-sido.json',
        );
    }

    public function testSeoulResolvesToKoreaSeoul(): void
    {
        $result = $this->resolver->resolve(37.5665, 126.9780); // 서울시청
        $this->assertSame('KR', $result['countryCode']);
        $this->assertSame('KR-11', $result['regionCode']);
    }

    public function testBusanResolvesToKoreaBusan(): void
    {
        $result = $this->resolver->resolve(35.1796, 129.0756); // 부산시청
        $this->assertSame('KR', $result['countryCode']);
        $this->assertSame('KR-26', $result['regionCode']);
    }

    public function testJejuResolvesToKoreaJeju(): void
    {
        $result = $this->resolver->resolve(33.4996, 126.5312); // 제주시
        $this->assertSame('KR', $result['countryCode']);
        $this->assertSame('KR-49', $result['regionCode']);
    }

    public function testTokyoResolvesToJapanWithoutRegion(): void
    {
        $result = $this->resolver->resolve(35.6762, 139.6503); // 도쿄
        $this->assertSame('JP', $result['countryCode']);
        $this->assertNull($result['regionCode']);
    }

    public function testPacificOceanResolvesToNull(): void
    {
        $result = $this->resolver->resolve(0.0, -150.0); // 태평양 한가운데
        $this->assertNull($result['countryCode']);
        $this->assertNull($result['regionCode']);
    }

    public function testEnrichAllSkipsLocationsWithoutCoordinates(): void
    {
        $withGps = new PhotoLocation('a.jpg', 37.5665, 126.9780, '2026-07-20 09:00:00');
        $noGps = new PhotoLocation('b.jpg', null, null, '2026-07-20 10:00:00');

        $enriched = $this->resolver->enrichAll([$withGps, $noGps]);

        $this->assertSame('KR', $enriched[0]->countryCode);
        $this->assertSame('KR-11', $enriched[0]->regionCode);
        $this->assertNull($enriched[1]->countryCode);
        $this->assertNull($enriched[1]->regionCode);
    }
}
```

- [ ] **Step 2: 실패 확인**

```bash
vendor/bin/phpunit tests/Unit/RegionResolverTest.php --no-coverage
```

Expected: FAIL — `Class "App\Services\Region\RegionResolver" not found`

- [ ] **Step 3: 구현** — `app/Services/Region/RegionResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Region;

use App\Services\Ingest\PhotoLocation;
use RuntimeException;

/**
 * 좌표 → 지역(국가·국내 시·도) 오프라인 판별.
 *
 * 번들 GeoJSON(bbox 프리체크 + ray casting point-in-polygon)만 사용한다 — 외부 API 없음.
 * 한국 좌표는 세계 경계(110m, 해안 거침)보다 해상도 높은 시·도 경계를 먼저 판별해
 * 해안 사진이 바다로 새는 것을 줄인다. 인제스트·백필에서만 로드된다(요청당 lazy 1회 파싱).
 */
final class RegionResolver
{
    /** @var list<array{iso: string, polygons: list<array{bbox: array{float, float, float, float}, rings: list<list<array{float, float}>>}>}>|null */
    private ?array $countryFeatures = null;

    /** @var list<array{iso: string, polygons: list<array{bbox: array{float, float, float, float}, rings: list<list<array{float, float}>>}>}>|null */
    private ?array $sidoFeatures = null;

    public function __construct(
        private readonly string $worldGeoJsonPath,
        private readonly string $sidoGeoJsonPath,
    ) {
    }

    /**
     * @return array{countryCode: ?string, regionCode: ?string}
     */
    public function resolve(float $lat, float $lng): array
    {
        $sido = $this->matchIso($this->sidoFeatures(), $lat, $lng);
        if ($sido !== null) {
            return ['countryCode' => 'KR', 'regionCode' => $sido];
        }

        return ['countryCode' => $this->matchIso($this->countryFeatures(), $lat, $lng), 'regionCode' => null];
    }

    /**
     * 좌표 있는 항목에 지역 코드를 입힌다(없으면 그대로 통과).
     *
     * @param list<PhotoLocation> $locations
     *
     * @return list<PhotoLocation>
     */
    public function enrichAll(array $locations): array
    {
        return array_map(function (PhotoLocation $location): PhotoLocation {
            if ($location->lat === null || $location->lng === null) {
                return $location;
            }
            $region = $this->resolve($location->lat, $location->lng);

            return $location->withRegion($region['countryCode'], $region['regionCode']);
        }, $locations);
    }

    /**
     * @param list<array{iso: string, polygons: list<array{bbox: array{float, float, float, float}, rings: list<list<array{float, float}>>}>}> $features
     */
    private function matchIso(array $features, float $lat, float $lng): ?string
    {
        foreach ($features as $feature) {
            foreach ($feature['polygons'] as $polygon) {
                [$minLng, $minLat, $maxLng, $maxLat] = $polygon['bbox'];
                if ($lng < $minLng || $lng > $maxLng || $lat < $minLat || $lat > $maxLat) {
                    continue;
                }
                if ($this->pointInPolygon($lat, $lng, $polygon['rings'])) {
                    return $feature['iso'];
                }
            }
        }

        return null;
    }

    /**
     * 외곽 링 안에 있고 구멍(holes) 안에 없으면 true.
     *
     * @param list<list<array{float, float}>> $rings 첫 링=외곽, 나머지=구멍
     */
    private function pointInPolygon(float $lat, float $lng, array $rings): bool
    {
        if ($rings === [] || ! $this->pointInRing($lat, $lng, $rings[0])) {
            return false;
        }
        $ringCount = count($rings);
        for ($i = 1; $i < $ringCount; $i++) {
            if ($this->pointInRing($lat, $lng, $rings[$i])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ray casting — 경도 방향 반직선과 링 변의 교차 횟수 홀짝 판정.
     *
     * @param list<array{float, float}> $ring [lng, lat] 순서(GeoJSON 좌표 규약)
     */
    private function pointInRing(float $lat, float $lng, array $ring): bool
    {
        $inside = false;
        $count = count($ring);
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if (($yi > $lat) !== ($yj > $lat)
                && $lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /**
     * @return list<array{iso: string, polygons: list<array{bbox: array{float, float, float, float}, rings: list<list<array{float, float}>>}>}>
     */
    private function countryFeatures(): array
    {
        return $this->countryFeatures ??= $this->loadFeatures($this->worldGeoJsonPath);
    }

    /**
     * @return list<array{iso: string, polygons: list<array{bbox: array{float, float, float, float}, rings: list<list<array{float, float}>>}>}>
     */
    private function sidoFeatures(): array
    {
        return $this->sidoFeatures ??= $this->loadFeatures($this->sidoGeoJsonPath);
    }

    /**
     * GeoJSON 을 판별용 구조로 정규화한다 — iso 없는 feature(미승인 지역 등)는 건너뛴다.
     *
     * @return list<array{iso: string, polygons: list<array{bbox: array{float, float, float, float}, rings: list<list<array{float, float}>>}>}>
     */
    private function loadFeatures(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("경계 GeoJSON 을 읽을 수 없습니다: {$path}");
        }
        /** @var array{features: list<array{properties: array{iso: ?string}, geometry: array{type: string, coordinates: array<int, mixed>}}>} $geo */
        $geo = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $features = [];
        foreach ($geo['features'] as $feature) {
            $iso = $feature['properties']['iso'] ?? null;
            if ($iso === null) {
                continue;
            }
            $geometry = $feature['geometry'];
            /** @var list<list<list<array{float, float}>>> $multi */
            $multi = $geometry['type'] === 'Polygon' ? [$geometry['coordinates']] : $geometry['coordinates'];

            $polygons = [];
            foreach ($multi as $rings) {
                $polygons[] = ['bbox' => $this->ringBbox($rings[0]), 'rings' => $rings];
            }
            $features[] = ['iso' => $iso, 'polygons' => $polygons];
        }

        return $features;
    }

    /**
     * @param list<array{float, float}> $ring
     *
     * @return array{float, float, float, float} [minLng, minLat, maxLng, maxLat]
     */
    private function ringBbox(array $ring): array
    {
        $minLng = $minLat = PHP_FLOAT_MAX;
        $maxLng = $maxLat = -PHP_FLOAT_MAX;
        foreach ($ring as [$lng, $lat]) {
            $minLng = min($minLng, $lng);
            $minLat = min($minLat, $lat);
            $maxLng = max($maxLng, $lng);
            $maxLat = max($maxLat, $lat);
        }

        return [$minLng, $minLat, $maxLng, $maxLat];
    }
}
```

- [ ] **Step 4: 테스트 통과 확인**

```bash
vendor/bin/phpunit tests/Unit/RegionResolverTest.php --no-coverage
```

Expected: PASS (6 tests). 만약 `testJejuResolvesToKoreaJeju` 만 실패하면 시·도 GeoJSON 의 제주 포함 여부를 확인하고 결과를 보고(스킵 금지).

- [ ] **Step 5: 커밋**

```bash
composer analyse
git add app/Services/Region/RegionResolver.php tests/Unit/RegionResolverTest.php
git commit -m "✨ feat: 좌표→지역 오프라인 판별 RegionResolver 추가"
```

---

### Task 3: FootprintService + 집계 쿼리 (TDD)

**Files:**
- Create: `app/Services/FootprintService.php`
- Modify: `app/Models/PhotoLocationModel.php` (집계 메서드 2개 추가)
- Test: `tests/Unit/FootprintServiceTest.php`

**Interfaces:**
- Consumes: Task 1 컬럼
- Produces:
  - `PhotoLocationModel::countByCountry(int $userId): list<array{code: string, photos: int}>`
  - `PhotoLocationModel::countByRegion(int $userId): list<array{code: string, photos: int}>`
  - `FootprintService::buildForUser(int $userId): array{countries: list<array{code: string, photos: int}>, regions: list<array{code: string, photos: int}>, stats: array{countryCount: int, regionCount: int}}`

- [ ] **Step 1: 실패하는 테스트 작성** — `tests/Unit/FootprintServiceTest.php`(모델 Mock — 기존 서비스 테스트 패턴):

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PhotoLocationModel;
use App\Services\FootprintService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * 발자국 집계 조립 검증 — DB 는 Mock.
 */
final class FootprintServiceTest extends CIUnitTestCase
{
    public function testBuildForUserAssemblesCountsAndStats(): void
    {
        $model = $this->createMock(PhotoLocationModel::class);
        $model->method('countByCountry')->with(7)->willReturn([
            ['code' => 'KR', 'photos' => 310],
            ['code' => 'JP', 'photos' => 42],
        ]);
        $model->method('countByRegion')->with(7)->willReturn([
            ['code' => 'KR-11', 'photos' => 250],
            ['code' => 'KR-49', 'photos' => 60],
        ]);

        $result = (new FootprintService($model))->buildForUser(7);

        $this->assertSame(2, $result['stats']['countryCount']);
        $this->assertSame(2, $result['stats']['regionCount']);
        $this->assertSame('KR', $result['countries'][0]['code']);
        $this->assertSame(60, $result['regions'][1]['photos']);
    }

    public function testBuildForUserWithNoPhotos(): void
    {
        $model = $this->createMock(PhotoLocationModel::class);
        $model->method('countByCountry')->willReturn([]);
        $model->method('countByRegion')->willReturn([]);

        $result = (new FootprintService($model))->buildForUser(7);

        $this->assertSame([], $result['countries']);
        $this->assertSame([], $result['regions']);
        $this->assertSame(0, $result['stats']['countryCount']);
        $this->assertSame(0, $result['stats']['regionCount']);
    }
}
```

- [ ] **Step 2: 실패 확인**

```bash
vendor/bin/phpunit tests/Unit/FootprintServiceTest.php --no-coverage
```

Expected: FAIL — `Class "App\Services\FootprintService" not found`

- [ ] **Step 3: 구현**

`app/Services/FootprintService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PhotoLocationModel;

/**
 * 발자국 지도 집계 — 방문 국가·국내 시·도별 사진 수와 요약 통계를 조립한다.
 */
final class FootprintService
{
    public function __construct(
        private readonly PhotoLocationModel $photoLocations,
    ) {
    }

    /**
     * @return array{countries: list<array{code: string, photos: int}>, regions: list<array{code: string, photos: int}>, stats: array{countryCount: int, regionCount: int}}
     */
    public function buildForUser(int $userId): array
    {
        $countries = $this->photoLocations->countByCountry($userId);
        $regions = $this->photoLocations->countByRegion($userId);

        return [
            'countries' => $countries,
            'regions' => $regions,
            'stats' => [
                'countryCount' => count($countries),
                'regionCount' => count($regions),
            ],
        ];
    }
}
```

`PhotoLocationModel` 에 추가(기존 메서드들 뒤):

```php
    /**
     * 국가별 사진 수(판별된 것만) — 발자국 지도 집계용.
     *
     * @return list<array{code: string, photos: int}>
     */
    public function countByCountry(int $userId): array
    {
        /** @var list<array{code: string, photos: int|string}> $rows */
        $rows = $this->builder()
            ->select('country_code AS code, COUNT(*) AS photos')
            ->where('user_id', $userId)
            ->where('country_code IS NOT NULL', null, false)
            ->groupBy('country_code')
            ->orderBy('photos', 'DESC')
            ->get()->getResultArray();

        return array_map(
            static fn (array $row): array => ['code' => (string) $row['code'], 'photos' => (int) $row['photos']],
            $rows,
        );
    }

    /**
     * 국내 시·도별 사진 수(판별된 것만) — 발자국 지도 집계용.
     *
     * @return list<array{code: string, photos: int}>
     */
    public function countByRegion(int $userId): array
    {
        /** @var list<array{code: string, photos: int|string}> $rows */
        $rows = $this->builder()
            ->select('region_code AS code, COUNT(*) AS photos')
            ->where('user_id', $userId)
            ->where('region_code IS NOT NULL', null, false)
            ->groupBy('region_code')
            ->orderBy('photos', 'DESC')
            ->get()->getResultArray();

        return array_map(
            static fn (array $row): array => ['code' => (string) $row['code'], 'photos' => (int) $row['photos']],
            $rows,
        );
    }
```

- [ ] **Step 4: 테스트 통과 확인 및 커밋**

```bash
vendor/bin/phpunit tests/Unit/FootprintServiceTest.php --no-coverage
composer analyse
git add app/Services/FootprintService.php app/Models/PhotoLocationModel.php tests/Unit/FootprintServiceTest.php
git commit -m "✨ feat: 발자국 집계 FootprintService·모델 집계 쿼리 추가"
```

---

### Task 4: 컨트롤러·라우트·서비스 등록·인제스트 연동

**Files:**
- Create: `app/Controllers/FootprintController.php`
- Modify: `app/Config/Services.php` (팩토리 2개), `app/Config/Routes.php` (라우트 2개), `app/Controllers/TakeoutController.php` (enrich 훅), `app/Views/partials/nav.php` (발자국 링크)

**Interfaces:**
- Consumes: Task 2 `RegionResolver`, Task 3 `FootprintService`
- Produces: `GET /footprint`(페이지), `GET /footprint/data`(JSON), `service('regionResolver')`, `service('footprint')`

- [ ] **Step 1: Services 팩토리 추가** — `app/Config/Services.php` 클래스 끝에(use 문에 `App\Services\FootprintService`, `App\Services\Region\RegionResolver` 추가):

```php
    /**
     * 좌표→지역 오프라인 판별기(발자국 지도) — 인제스트·백필에서 사용.
     */
    public static function regionResolver(bool $getShared = true): RegionResolver
    {
        if ($getShared) {
            return static::getSharedInstance('regionResolver');
        }

        return new RegionResolver(
            FCPATH . 'assets/geo/world-countries.json',
            FCPATH . 'assets/geo/kr-sido.json',
        );
    }

    /**
     * 발자국 지도 집계 서비스.
     */
    public static function footprint(bool $getShared = true): FootprintService
    {
        if ($getShared) {
            return static::getSharedInstance('footprint');
        }

        return new FootprintService(new PhotoLocationModel());
    }
```

- [ ] **Step 2: 컨트롤러** — `app/Controllers/FootprintController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * 발자국 지도 — 방문 국가·시·도 choropleth 페이지와 집계 JSON.
 */
final class FootprintController extends BaseController
{
    /**
     * 발자국 페이지(GET /footprint). 미로그인 시 OAuth 로그인으로 리다이렉트한다.
     */
    public function page(): ResponseInterface|RedirectResponse|string
    {
        if ($this->currentUserId() === null) {
            return redirect()->to('/auth/google');
        }

        helper('url');

        return view('footprint', [
            'dataUrl' => site_url('footprint/data'),
            'uploadUrl' => site_url('upload'),
            'mapUrl' => site_url('map'),
            'tripsUrl' => site_url('trips'),
            'logoutUrl' => site_url('auth/logout'),
        ]);
    }

    /**
     * 집계 JSON(GET /footprint/data).
     */
    public function data(): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => '로그인이 필요합니다.']);
        }

        return $this->response->setJSON(service('footprint')->buildForUser($userId));
    }
}
```

- [ ] **Step 3: 라우트 추가** — `app/Config/Routes.php` 의 `$routes->get('map', ...)` 블록 근처에:

```php
// 발자국 지도 — 방문 국가·시·도 시각화
$routes->get('footprint', 'FootprintController::page');
$routes->get('footprint/data', 'FootprintController::data');
```

- [ ] **Step 4: 인제스트 enrich 훅** — `app/Controllers/TakeoutController.php` 의 `handleZipUpload()` 에서

```php
            $saved = model(PhotoLocationModel::class)->saveMany($userId, $result['locations']);
```

를 다음으로 교체:

```php
            // 발자국 지도용 지역 코드를 입힌 뒤 저장한다(좌표 없는 사진은 그대로 통과).
            $enriched = service('regionResolver')->enrichAll($result['locations']);
            $saved = model(PhotoLocationModel::class)->saveMany($userId, $enriched);
```

이후 `$result['locations']` 를 참조하는 후속 코드(`latestKstDate`, `deleteThumbnails` 등)가 있으면 그대로 둔다 — 좌표·시각은 동일하고 지역 코드만 추가되므로 어느 쪽을 써도 무해하지만, 저장 대상은 반드시 `$enriched` 다.

- [ ] **Step 5: nav 링크 추가** — `app/Views/partials/nav.php` 의 "내 여행" 링크 다음에:

```php
    <a href="<?= esc(site_url('footprint'), 'attr') ?>">발자국</a>
```

파일 상단 주석 아래 PHP 블록에 `helper('url');` 한 줄 추가(모든 호출 뷰를 고치지 않기 위해 nav 가 직접 URL 을 만든다 — 기존 파라미터들은 그대로 둔다):

```php
helper('url'); // 발자국 링크는 nav 가 직접 생성 — 호출 뷰 전부에 파라미터를 추가하지 않기 위함
```

- [ ] **Step 6: 검증 및 커밋**

```bash
composer analyse && composer test
git add app/Controllers/FootprintController.php app/Config/Services.php app/Config/Routes.php app/Controllers/TakeoutController.php app/Views/partials/nav.php
git commit -m "✨ feat: 발자국 페이지·API 라우트와 인제스트 지역 판별 연동"
```

---

### Task 5: 발자국 페이지 뷰

**Files:**
- Create: `app/Views/footprint.php`

**Interfaces:**
- Consumes: `GET /footprint/data` JSON(Task 4), `/assets/geo/*.json`(Task 0, properties.iso/name)
- Produces: 없음(말단 뷰)

- [ ] **Step 1: 뷰 작성** — `app/Views/footprint.php` (기존 map.php 의 헤드·nav 패턴을 따른다):

```php
<?php

declare(strict_types=1);

/**
 * 발자국 지도 — 방문 국가·국내 시·도를 색칠한 choropleth.
 *
 * @var string $dataUrl   집계 JSON API URL(GET /footprint/data)
 * @var string $uploadUrl
 * @var string $mapUrl
 * @var string $tripsUrl
 * @var string $logoutUrl
 */
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iter — 발자국 지도</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png">
    <link rel="stylesheet" href="/assets/nav.css">
    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
        crossorigin="">
    <style>
        html, body { margin: 0; height: 100%; font-family: system-ui, sans-serif; }
        body { display: flex; flex-direction: column; }
        #stats-bar {
            display: flex; gap: 12px; padding: 12px 16px; background: #fff;
            border-bottom: 1px solid #eee; font-size: 14px; align-items: center;
        }
        .stat-card {
            background: #f0f4ff; border-radius: 8px; padding: 8px 14px; color: #1a3e8e;
        }
        .stat-card strong { font-size: 16px; color: #1a73e8; }
        #footprint-map { flex: 1; min-height: 0; }
        .region-tooltip { font-size: 12px; }
    </style>
</head>
<body>
    <?= view('partials/nav', ['uploadUrl' => $uploadUrl, 'mapUrl' => $mapUrl, 'tripsUrl' => $tripsUrl, 'logoutUrl' => $logoutUrl]) ?>
    <div id="stats-bar">
        <span class="stat-card">방문 국가 <strong id="stat-countries">-</strong>개국</span>
        <span class="stat-card">국내 시·도 <strong id="stat-regions">-</strong>/17</span>
    </div>
    <div id="footprint-map" data-url="<?= esc($dataUrl, 'attr') ?>"></div>

    <script
        src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""></script>
    <script>
        (function () {
            var mapEl = document.getElementById('footprint-map');
            var map = L.map('footprint-map', { minZoom: 2, maxZoom: 10, worldCopyJump: true })
                .setView([30, 60], 2);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(map);

            var VISITED_STYLE = { color: '#1a73e8', weight: 1, fillColor: '#1a73e8', fillOpacity: 0.5 };
            var EMPTY_STYLE = { color: '#bbb', weight: 1, fillColor: '#ccc', fillOpacity: 0.15 };

            // 집계 + 경계 두 파일을 함께 받아 그린다.
            Promise.all([
                fetch(mapEl.dataset.url, { headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }),
                fetch('/assets/geo/world-countries.json').then(function (r) { return r.json(); }),
                fetch('/assets/geo/kr-sido.json').then(function (r) { return r.json(); })
            ]).then(function (results) {
                var data = results[0];
                var world = results[1];
                var sido = results[2];

                document.getElementById('stat-countries').textContent = data.stats.countryCount;
                document.getElementById('stat-regions').textContent = data.stats.regionCount;

                var photosByCode = {}; // iso 코드 → 사진 수 (국가·시·도 공용)
                (data.countries || []).forEach(function (c) { photosByCode[c.code] = c.photos; });
                (data.regions || []).forEach(function (r) { photosByCode[r.code] = r.photos; });

                function styleFor(feature) {
                    return photosByCode[feature.properties.iso] ? VISITED_STYLE : EMPTY_STYLE;
                }

                function bindTooltip(feature, layer) {
                    var n = photosByCode[feature.properties.iso];
                    var label = feature.properties.name + (n ? ' · 사진 ' + n + '장' : ' · 미방문');
                    layer.bindTooltip(label, { sticky: true, className: 'region-tooltip' });
                }

                // 세계 — 한국은 시·도 레이어로 대체하므로 국가 폴리곤에서 제외한다.
                L.geoJSON(world, {
                    filter: function (feature) { return feature.properties.iso !== 'KR'; },
                    style: styleFor,
                    onEachFeature: bindTooltip
                }).addTo(map);

                L.geoJSON(sido, { style: styleFor, onEachFeature: bindTooltip }).addTo(map);
            }).catch(function () {
                document.getElementById('stats-bar').textContent = '발자국 데이터를 불러오지 못했습니다.';
            });
        })();
    </script>
</body>
</html>
```

- [ ] **Step 2: 문법·정적 검증**

```bash
php -l app/Views/footprint.php && composer analyse
```

- [ ] **Step 3: 커밋**

```bash
git add app/Views/footprint.php
git commit -m "✨ feat: 발자국 지도 페이지 추가 (choropleth + 통계 헤더)"
```

---

### Task 6: 백필 커맨드

**Files:**
- Create: `app/Commands/RegionBackfill.php`
- Modify: `app/Models/PhotoLocationModel.php` (백필 조회 메서드)

**Interfaces:**
- Consumes: Task 2 `service('regionResolver')`
- Produces: `php spark region:backfill`, `PhotoLocationModel::findUnresolvedBatch(int $afterId, int $limit): list<array{id: int, lat: float, lng: float}>`

- [ ] **Step 1: 모델 조회 메서드 추가**

```php
    /**
     * 지역 미판별(좌표는 있는) 행을 id 커서로 배치 조회한다 — region:backfill 용.
     *
     * country_code 가 끝내 null 로 남는 행(바다 등)이 있어도 커서가 전진하므로 무한 루프가 없다.
     *
     * @return list<array{id: int, lat: float, lng: float}>
     */
    public function findUnresolvedBatch(int $afterId, int $limit): array
    {
        /** @var list<array{id: int|string, lat: float|string, lng: float|string}> $rows */
        $rows = $this->builder()
            ->select('id, lat, lng')
            ->where('id >', $afterId)
            ->where('lat IS NOT NULL', null, false)
            ->where('country_code IS NULL', null, false)
            ->orderBy('id', 'ASC')
            ->limit($limit)
            ->get()->getResultArray();

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'lat' => (float) $row['lat'], 'lng' => (float) $row['lng']],
            $rows,
        );
    }
```

- [ ] **Step 2: 커맨드 작성** — `app/Commands/RegionBackfill.php` (기존 `CleanupCommand` 스타일 참조):

```php
<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\PhotoLocationModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * 기존 photo_locations 행의 지역 코드 일괄 판별 — php spark region:backfill
 *
 * 좌표는 있으나 country_code 가 비어 있는 행을 id 커서로 순회하며 RegionResolver 로 채운다.
 * 바다 등 판별 불가 행은 null 로 남는다(재실행해도 커서가 전진해 무한 루프 없음).
 */
final class RegionBackfill extends BaseCommand
{
    protected $group = 'Iter';
    protected $name = 'region:backfill';
    protected $description = '좌표는 있고 지역 코드가 없는 photo_locations 를 일괄 판별한다.';

    private const BATCH_SIZE = 500;

    public function run(array $params): void
    {
        $model = model(PhotoLocationModel::class);
        $resolver = service('regionResolver');

        $afterId = 0;
        $scanned = 0;
        $resolved = 0;

        while (true) {
            $rows = $model->findUnresolvedBatch($afterId, self::BATCH_SIZE);
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $afterId = $row['id'];
                $scanned++;
                $region = $resolver->resolve($row['lat'], $row['lng']);
                if ($region['countryCode'] === null) {
                    continue; // 바다 등 — null 유지
                }
                $model->update($row['id'], [
                    'country_code' => $region['countryCode'],
                    'region_code' => $region['regionCode'],
                ]);
                $resolved++;
            }

            CLI::write("진행: {$scanned}건 스캔, {$resolved}건 판별");
        }

        CLI::write("완료 — 총 {$scanned}건 중 {$resolved}건에 지역 코드를 채웠습니다.", 'green');
    }
}
```

- [ ] **Step 3: 검증 및 커밋**

```bash
composer analyse && composer test
php spark list | grep region
git add app/Commands/RegionBackfill.php app/Models/PhotoLocationModel.php
git commit -m "✨ feat: 지역 코드 일괄 백필 spark 커맨드 추가"
```

(`php spark list` 는 DB 연결 없이도 커맨드 등록 확인 가능해야 하나, DB 오류로 실패하면 그 사실만 보고.)

---

### Task 7: 통합 검증 및 dev 머지 (컨트롤러 직접 수행)

**Files:** 없음 (검증·git 조작만; 브라우저 검증용 임시 라우트는 검증 후 원복)

- [ ] **Step 1: 전체 로컬 게이트**

```bash
composer ci
```

Expected: CS Fixer·PHPStan·PHPUnit 모두 통과.

- [ ] **Step 2: 브라우저 검증** — 동선 재생 때와 동일하게 임시 dev 라우트(`ENVIRONMENT === 'development'` 가드)로 `view('footprint', ...)` + canned `/footprint/data` JSON 을 서빙해 확인:
  - 통계 카드 숫자 표시, 방문 국가 채색(예: JP), 한국이 국가 폴리곤이 아닌 시·도 단위로 렌더·일부 시·도만 채색되는지, 호버 툴팁(지역명·사진 N장/미방문), 콘솔 에러 없음.
  - 확인 후 Routes.php 원복(`git checkout app/Config/Routes.php` — 커밋 금지).

- [ ] **Step 3: dev 머지·푸시**

```bash
git checkout dev && git pull origin dev
git merge --no-ff feature/footprint-map -m "🔀 merge: 여행 발자국 지도 기능"
git push origin dev
git branch -d feature/footprint-map
```

- [ ] **Step 4: 배포 안내** — dev → main 머지는 사용자 확인 후. **이 배포는 마이그레이션 포함**:

```bash
php spark migrate
php spark region:backfill   # 기존 사진 지역 코드 일괄 채움(1회)
```

두 명령은 배포 절차에서 **별도 필수 단계로 강조**해 안내한다.
