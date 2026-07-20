# Google Takeout zip 업로드 기반 GPS 적재 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Google Photos Picker API가 다운로드 원본에서 GPS EXIF를 의도적으로 제거한다는 사실이 확인됨에 따라, Picker 기반 파이프라인을 완전히 제거하고 Google Takeout zip 업로드 기반으로 GPS 적재를 재구현한다.

**Architecture:** 사용자가 `takeout.google.com`에서 직접 내보낸 zip을 업로드하면(`POST /takeout/upload`), 서버가 동기적으로 zip을 열어 `.json` 사이드카(`geoData`)에서 좌표·촬영 시각을 파싱하고, 사진 파일을 실제 사진 파일과 매칭해 썸네일을 생성한 뒤 `photo_locations`에 저장한다. 지도 시각화(`RouteVisualizationService`)는 무변경. 200장 상한, 동기 처리(큐 인프라 없음).

**Tech Stack:** CodeIgniter 4, PHP `ZipArchive`, GD(기존 `GdThumbnailGenerator` 재사용), vanilla JS(업로드 폼).

## Global Constraints

- 한 번에 처리할 사진 개수 상한: **200장**(초과분은 앞에서부터만 처리, 전체 후보 수를 응답에 포함).
- 처리 방식: **동기**(업로드 요청 안에서 바로 처리, 큐 인프라 신규 구축 안 함).
- zip 업로드 크기 상한: 애플리케이션 레벨 500MB(초과 시 413).
- 사진 ↔ JSON 매칭은 **정확히 일치**하는 파일명만 처리(`.json` 접미사를 뗀 이름). 매칭 실패는 해당 항목만 조용히 스킵.
- GPS 필드 우선순위: `geoData` → (0.0, 0.0)이면 `geoDataExif` 폴백 → 그래도 0.0이면 위치 없음으로 스킵.
- `photo_locations.google_media_item_id` 컬럼은 `source_item_id`로 rename(값은 zip 안 사진 파일명). `PhotoLocation::$mediaItemId`·`toArray()`의 `media_item_id` 키는 무변경(범용 식별자 이름이라 그대로 둔다).
- Picker 관련 코드는 전부 삭제(도달 불가능해진 죽은 코드). `GooglePhotosAuthService`(로그인용)와 `RouteVisualizationService`/`RouteController`/지도(`/map`)는 무변경.
- OAuth 스코프에서 `photospicker.mediaitems.readonly` 제거(더 이상 Picker API 호출 안 함).
- `SessionRateLimitFilter`를 삭제하지 않고 `/takeout/upload`에 재적용.
- 업로드 파일 검증(`isValid()`/`move()`)은 `is_uploaded_file()`에 의존해 PHPUnit 테스트에서 시뮬레이션이 불가능하므로, `UploadedZipHandlerInterface`로 추상화해 컨트롤러 테스트에서는 mock으로 대체한다. 실제 업로드 성공 경로는 실제 브라우저로 수동 확인.
- 커밋 메시지 형식: 이모지 + Conventional Commits 접두어 + 한국어 설명(전역 git-workflow 규칙).
- 각 태스크 커밋 전 `dev`가 그 사이 원격에서 앞서갔는지 반드시 `git fetch && git log --oneline dev..origin/dev`로 확인한다(이 저장소는 다른 세션/작업자가 동시에 `dev`에 커밋하는 일이 잦았다).

---

### Task 1: `photo_locations` 컬럼명 변경 (`google_media_item_id` → `source_item_id`)

**Files:**
- Create: `Iter/app/Database/Migrations/2026-07-20-060000_RenameGoogleMediaItemIdToSourceItemId.php`
- Modify: `Iter/app/Models/PhotoLocationModel.php`
- Modify: `Iter/app/Services/RouteVisualizationService.php`
- Modify: `Iter/tests/database/PhotoLocationModelTest.php`
- Modify: `Iter/tests/unit/RouteVisualizationServiceTest.php`

**Interfaces:**
- Produces: `photo_locations.source_item_id` 컬럼(기존 `google_media_item_id` 대체), `PhotoLocationModel::saveMany()`/`findByUserOrdered()`가 이 컬럼명을 사용. 이후 모든 태스크가 이 컬럼명을 전제로 한다.

- [ ] **Step 1: 실패하는 테스트로 갱신**

`Iter/tests/database/PhotoLocationModelTest.php` 전체를 아래로 교체:

```php
<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\PhotoLocationModel;
use App\Models\UserModel;
use App\Services\Ingest\PhotoLocation;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class PhotoLocationModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $refresh = true;
    protected $namespace = 'App';

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        // photo_locations.user_id 는 users FK 이므로 사용자를 먼저 시드한다.
        $this->userId = (new UserModel())->upsertByGoogleSub('sub-loc', 'loc@example.com', 'Loc');
    }

    public function testSaveManyInsertsRows(): void
    {
        $saved = (new PhotoLocationModel())->saveMany($this->userId, [
            new PhotoLocation('media-1', 37.5, 127.0, '2024-03-15 09:00:00'),
            new PhotoLocation('media-2', 37.6, 127.1, '2024-03-15 12:00:00'),
        ]);

        $this->assertSame(2, $saved);
        $this->seeInDatabase('photo_locations', [
            'user_id' => $this->userId,
            'source_item_id' => 'media-1',
        ]);
        $this->seeInDatabase('photo_locations', [
            'user_id' => $this->userId,
            'source_item_id' => 'media-2',
        ]);
    }

    public function testSaveManyPersistsThumbnailPath(): void
    {
        (new PhotoLocationModel())->saveMany($this->userId, [
            new PhotoLocation('media-thumb', 37.5, 127.0, '2024-03-15 09:00:00', '/thumbs/media-thumb.jpg'),
        ]);

        $this->seeInDatabase('photo_locations', [
            'source_item_id' => 'media-thumb',
            'thumbnail_path' => '/thumbs/media-thumb.jpg',
        ]);
    }

    public function testSaveManySkipsDuplicateMediaItemIds(): void
    {
        $model = new PhotoLocationModel();
        $model->saveMany($this->userId, [new PhotoLocation('dup', 37.5, 127.0, '2024-03-15 09:00:00')]);

        // 같은 source_item_id 재적재는 건너뛴다(idempotent).
        $saved = $model->saveMany($this->userId, [
            new PhotoLocation('dup', 38.0, 128.0, '2024-03-16 09:00:00'),
            new PhotoLocation('fresh', 37.7, 127.2, '2024-03-16 10:00:00'),
        ]);

        $this->assertSame(1, $saved);
        $this->assertSame(1, $model->where('source_item_id', 'dup')->countAllResults());
        $this->seeInDatabase('photo_locations', ['source_item_id' => 'fresh']);
    }
}
```

`Iter/tests/unit/RouteVisualizationServiceTest.php` 전체를 아래로 교체:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PhotoLocationModel;
use App\Services\RouteVisualizationService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class RouteVisualizationServiceTest extends CIUnitTestCase
{
    /**
     * findByUserOrdered 가 주어진 행들을 돌려주도록 스텁한 서비스.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function serviceWithRows(array $rows): RouteVisualizationService
    {
        $model = $this->createMock(PhotoLocationModel::class);
        $model->method('findByUserOrdered')->willReturn($rows);

        return new RouteVisualizationService($model);
    }

    public function testGroupsPointsByDateWithDistinctColors(): void
    {
        $service = $this->serviceWithRows([
            ['source_item_id' => 'm1', 'lat' => '37.5000000', 'lng' => '127.0000000', 'taken_at' => '2024-03-15 09:00:00'],
            ['source_item_id' => 'm2', 'lat' => '37.6000000', 'lng' => '127.1000000', 'taken_at' => '2024-03-15 12:00:00'],
            ['source_item_id' => 'm3', 'lat' => '35.1000000', 'lng' => '129.0000000', 'taken_at' => '2024-03-16 08:00:00'],
        ]);

        $result = $service->buildForUser(1);

        $this->assertCount(2, $result['dates']);
        $this->assertSame('2024-03-15', $result['dates'][0]['date']);
        $this->assertSame('2024-03-16', $result['dates'][1]['date']);
        $this->assertCount(2, $result['dates'][0]['points']);
        $this->assertCount(1, $result['dates'][1]['points']);

        // 날짜마다 색상이 달라야 한다.
        $this->assertNotSame($result['dates'][0]['color'], $result['dates'][1]['color']);
    }

    public function testPointShapeHasFloatCoordsAndMediaItemId(): void
    {
        $service = $this->serviceWithRows([
            ['source_item_id' => 'm1', 'lat' => '37.5000000', 'lng' => '127.0000000', 'taken_at' => '2024-03-15 09:00:00'],
        ]);

        $point = $service->buildForUser(1)['dates'][0]['points'][0];

        $this->assertSame('m1', $point['media_item_id']);
        $this->assertIsFloat($point['lat']);
        $this->assertIsFloat($point['lng']);
        $this->assertEqualsWithDelta(37.5, $point['lat'], 0.0001);
        $this->assertSame('2024-03-15 09:00:00', $point['taken_at']);
    }

    public function testPointsWithinDateKeepChronologicalOrder(): void
    {
        $service = $this->serviceWithRows([
            ['source_item_id' => 'early', 'lat' => '37.5', 'lng' => '127.0', 'taken_at' => '2024-03-15 09:00:00'],
            ['source_item_id' => 'late', 'lat' => '37.6', 'lng' => '127.1', 'taken_at' => '2024-03-15 18:00:00'],
        ]);

        $points = $service->buildForUser(1)['dates'][0]['points'];

        $this->assertSame('early', $points[0]['media_item_id']);
        $this->assertSame('late', $points[1]['media_item_id']);
    }

    public function testReturnsEmptyDatesWhenNoLocations(): void
    {
        $service = $this->serviceWithRows([]);

        $this->assertSame(['dates' => []], $service->buildForUser(1));
    }
}
```

- [ ] **Step 2: 테스트 실패 확인**

Run: `cd Iter && vendor/bin/phpunit tests/database/PhotoLocationModelTest.php tests/unit/RouteVisualizationServiceTest.php`
Expected: `source_item_id` 컬럼이 아직 없어(`google_media_item_id`만 존재) DB 테스트가 컬럼 없음 에러로 실패. `RouteVisualizationServiceTest`는 `$row['google_media_item_id']`를 읽는 기존 코드 때문에 `media_item_id`가 빈 문자열로 나와 실패.

- [ ] **Step 3: 마이그레이션 작성**

`Iter/app/Database/Migrations/2026-07-20-060000_RenameGoogleMediaItemIdToSourceItemId.php` 신규 생성:

```php
<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class RenameGoogleMediaItemIdToSourceItemId extends Migration
{
    public function up(): void
    {
        $this->forge->modifyColumn('photo_locations', [
            'google_media_item_id' => [
                'name' => 'source_item_id',
                'type' => 'VARCHAR',
                'constraint' => 255,
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->modifyColumn('photo_locations', [
            'source_item_id' => [
                'name' => 'google_media_item_id',
                'type' => 'VARCHAR',
                'constraint' => 255,
            ],
        ]);
    }
}
```

(MySQL은 `CHANGE COLUMN`으로 이름을 바꿔도 기존 유니크 인덱스 `uniq_photo_locations_user_media`가 자동으로 새 컬럼명을 참조하므로 인덱스를 별도로 재생성할 필요가 없다.)

- [ ] **Step 4: `PhotoLocationModel` 갱신**

`Iter/app/Models/PhotoLocationModel.php` 전체를 아래로 교체:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Ingest\PhotoLocation;
use CodeIgniter\Model;

class PhotoLocationModel extends Model
{
    protected $table = 'photo_locations';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = '';

    /**
     * @var list<string>
     */
    protected $allowedFields = [
        'user_id',
        'source_item_id',
        'lat',
        'lng',
        'thumbnail_path',
        'taken_at',
    ];

    /**
     * 사용자 좌표를 촬영 시각 오름차순으로 조회한다(동선 조합용, 필요 컬럼만).
     *
     * @return list<array<string, mixed>>
     */
    public function findByUserOrdered(int $userId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->select('source_item_id, lat, lng, thumbnail_path, taken_at')
            ->where('user_id', $userId)
            ->orderBy('taken_at', 'ASC')
            ->findAll();

        return $rows;
    }

    /**
     * 동선 좌표들을 저장한다. 사용자당 이미 적재된 source_item_id 는 건너뛴다(idempotent).
     *
     * @param list<PhotoLocation> $locations
     *
     * @return int 실제로 삽입된 건수
     */
    public function saveMany(int $userId, array $locations): int
    {
        if ($locations === []) {
            return 0;
        }

        $existing = $this->existingSourceItemIds($userId, array_map(
            static fn (PhotoLocation $l): string => $l->mediaItemId,
            $locations,
        ));

        $rows = [];
        foreach ($locations as $location) {
            if (isset($existing[$location->mediaItemId])) {
                continue; // 중복 제외
            }
            // 같은 배치 안의 중복도 한 번만.
            $existing[$location->mediaItemId] = true;

            $rows[] = [
                'user_id' => $userId,
                'source_item_id' => $location->mediaItemId,
                'lat' => $location->lat,
                'lng' => $location->lng,
                'thumbnail_path' => $location->thumbnailPath,
                'taken_at' => $location->takenAt,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        return (int) $this->insertBatch($rows);
    }

    /**
     * 주어진 source_item_id 중 이미 저장된 것을 집합(키=id)으로 반환한다.
     *
     * @param list<string> $sourceItemIds
     *
     * @return array<string, true>
     */
    private function existingSourceItemIds(int $userId, array $sourceItemIds): array
    {
        if ($sourceItemIds === []) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->select('source_item_id')
            ->where('user_id', $userId)
            ->whereIn('source_item_id', array_values(array_unique($sourceItemIds)))
            ->findAll();

        $set = [];
        foreach ($rows as $row) {
            $set[(string) $row['source_item_id']] = true;
        }

        return $set;
    }
}
```

- [ ] **Step 5: `RouteVisualizationService` 갱신**

`Iter/app/Services/RouteVisualizationService.php`에서 아래 줄:

```php
                'media_item_id' => (string) ($row['google_media_item_id'] ?? ''),
```

를 다음으로 교체:

```php
                'media_item_id' => (string) ($row['source_item_id'] ?? ''),
```

- [ ] **Step 6: 테스트 통과 확인**

Run: `cd Iter && vendor/bin/phpunit tests/database/PhotoLocationModelTest.php tests/unit/RouteVisualizationServiceTest.php`
Expected: `OK (...)`, 실패 0건.

- [ ] **Step 7: 커밋**

```bash
cd Iter
git fetch origin && git log --oneline dev..origin/dev   # 비어있는지 확인, 아니면 먼저 pull
git add app/Database/Migrations/2026-07-20-060000_RenameGoogleMediaItemIdToSourceItemId.php \
        app/Models/PhotoLocationModel.php \
        app/Services/RouteVisualizationService.php \
        tests/database/PhotoLocationModelTest.php \
        tests/unit/RouteVisualizationServiceTest.php
git commit -m "♻️ refactor: photo_locations.google_media_item_id → source_item_id 컬럼명 변경"
```

---

### Task 2: Picker/EXIF 파이프라인 완전 삭제 + OAuth 스코프 축소

**Files:**
- Delete: `Iter/app/Services/PhotoPickerService.php`
- Delete: `Iter/app/Controllers/PickerController.php`
- Delete: `Iter/app/Services/PhotoIngestService.php`
- Delete: `Iter/app/Services/Ingest/CurlMultiDownloader.php`
- Delete: `Iter/app/Services/Ingest/MediaItemDownloaderInterface.php`
- Delete: `Iter/app/Services/Ingest/NativeExifExtractor.php`
- Delete: `Iter/app/Services/Ingest/ExifToolExtractor.php`
- Delete: `Iter/app/Services/Ingest/FallbackExifExtractor.php`
- Delete: `Iter/app/Services/Ingest/ExifGpsParser.php`
- Delete: `Iter/app/Services/Ingest/ExifExtractorInterface.php`
- Delete: `Iter/app/Services/GoogleApiUsageTracker.php`
- Delete: `Iter/app/Enums/GoogleApiName.php`
- Delete: `Iter/tests/unit/PhotoPickerServiceTest.php`
- Delete: `Iter/tests/feature/PickerControllerTest.php`
- Delete: `Iter/tests/unit/PhotoIngestServiceTest.php`
- Delete: `Iter/tests/unit/CurlMultiDownloaderTest.php`
- Delete: `Iter/tests/unit/NativeExifExtractorTest.php`
- Delete: `Iter/tests/unit/ExifToolExtractorTest.php`
- Delete: `Iter/tests/unit/FallbackExifExtractorTest.php`
- Delete: `Iter/tests/unit/ExifGpsParserTest.php`
- Delete: `Iter/tests/unit/GoogleApiUsageTrackerTest.php`
- Delete: `Iter/tests/_support/Ingest/RecordingCurlMultiDownloader.php`
- Modify: `Iter/app/Config/Routes.php`
- Modify: `Iter/app/Config/Services.php`
- Modify: `Iter/app/Config/GoogleOAuth.php`
- Modify: `Iter/tests/unit/GooglePhotosAuthServiceTest.php`
- Modify: `Iter/CLAUDE.md`

**Interfaces:**
- Consumes: Task 1의 `source_item_id`(간접, `RouteVisualizationService`가 이미 갱신됨).
- Produces: 정리된 `Config\Services`(Picker 관련 팩토리 제거, `GooglePhotosAuthService`만 남음), 정리된 `Config\Routes`(picker 라우트 전부 제거). Task 3~6이 여기 남은 빈 공간에 Takeout 관련 항목을 추가한다.

- [ ] **Step 1: 파일 삭제**

```bash
cd Iter
git rm app/Services/PhotoPickerService.php
git rm app/Controllers/PickerController.php
git rm app/Services/PhotoIngestService.php
git rm app/Services/Ingest/CurlMultiDownloader.php
git rm app/Services/Ingest/MediaItemDownloaderInterface.php
git rm app/Services/Ingest/NativeExifExtractor.php
git rm app/Services/Ingest/ExifToolExtractor.php
git rm app/Services/Ingest/FallbackExifExtractor.php
git rm app/Services/Ingest/ExifGpsParser.php
git rm app/Services/Ingest/ExifExtractorInterface.php
git rm app/Services/GoogleApiUsageTracker.php
git rm app/Enums/GoogleApiName.php
git rm tests/unit/PhotoPickerServiceTest.php
git rm tests/feature/PickerControllerTest.php
git rm tests/unit/PhotoIngestServiceTest.php
git rm tests/unit/CurlMultiDownloaderTest.php
git rm tests/unit/NativeExifExtractorTest.php
git rm tests/unit/ExifToolExtractorTest.php
git rm tests/unit/FallbackExifExtractorTest.php
git rm tests/unit/ExifGpsParserTest.php
git rm tests/unit/GoogleApiUsageTrackerTest.php
git rm tests/_support/Ingest/RecordingCurlMultiDownloader.php
```

- [ ] **Step 2: 라우트 정리**

`Iter/app/Config/Routes.php` 전체를 아래로 교체:

```php
<?php

declare(strict_types=1);

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->match(['get', 'head'], '/', 'Home::index');

// Google OAuth2 인증
$routes->get('auth/google', 'AuthController::redirect');
$routes->get('auth/google/callback', 'AuthController::callback');
$routes->get('auth/logout', 'AuthController::logout');

// 동선 시각화
$routes->get('routes', 'RouteController::data');
$routes->get('map', 'RouteController::map');
```

(Task 6에서 `takeout/upload` 라우트를 이 파일에 추가한다.)

- [ ] **Step 3: `Config\Services` 정리**

`Iter/app/Config/Services.php` 전체를 아래로 교체:

```php
<?php

declare(strict_types=1);

namespace Config;

use App\Models\OAuthTokenModel;
use App\Models\PhotoLocationModel;
use App\Models\UserModel;
use App\Services\GooglePhotosAuthService;
use App\Services\RouteVisualizationService;
use CodeIgniter\Config\BaseService;
use League\OAuth2\Client\Provider\Google;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    /**
     * Google OAuth2 인증 서비스.
     *
     * 시크릿은 Config\GoogleOAuth(.env)에서만 로드하고, league Google 프로바이더·모델·
     * 암호화기를 조립해 GooglePhotosAuthService 를 구성한다.
     */
    public static function googlePhotosAuth(bool $getShared = true): GooglePhotosAuthService
    {
        if ($getShared) {
            return static::getSharedInstance('googlePhotosAuth');
        }

        /** @var GoogleOAuth $config */
        $config = config(GoogleOAuth::class);

        $provider = new Google([
            'clientId' => $config->clientId,
            'clientSecret' => $config->clientSecret,
            'redirectUri' => $config->redirectUri,
            'scopes' => $config->scopes,
        ]);

        return new GooglePhotosAuthService(
            $provider,
            new OAuthTokenModel(),
            new UserModel(),
            static::encrypter(),
        );
    }

    /**
     * 날짜별 동선 시각화 서비스.
     *
     * photo_locations 를 읽어 날짜별 그룹·색상·시간순 좌표로 조합한다.
     */
    public static function routeVisualization(bool $getShared = true): RouteVisualizationService
    {
        if ($getShared) {
            return static::getSharedInstance('routeVisualization');
        }

        return new RouteVisualizationService(new PhotoLocationModel());
    }
}
```

(Task 5·6에서 `takeoutIngest()`·`uploadedZipHandler()` 팩토리를 이 파일에 추가한다.)

- [ ] **Step 4: OAuth 스코프 축소**

`Iter/app/Config/GoogleOAuth.php`에서 아래 블록:

```php
    /**
     * 요청 스코프.
     *
     * openid/email/profile 은 사용자 식별(sub·email·name)용,
     * photospicker.mediaitems.readonly 는 Picker API 접근용.
     *
     * @var list<string>
     */
    public array $scopes = [
        'openid',
        'email',
        'profile',
        'https://www.googleapis.com/auth/photospicker.mediaitems.readonly',
    ];
```

를 다음으로 교체:

```php
    /**
     * 요청 스코프.
     *
     * 사용자 식별(sub·email·name)용. Google Photos API(Picker/Library)는 다운로드
     * 원본에서 GPS EXIF 를 의도적으로 제거하므로 더는 호출하지 않는다 — GPS 는
     * Google Takeout zip 업로드로 얻는다(Iter/CLAUDE.md 참고).
     *
     * @var list<string>
     */
    public array $scopes = [
        'openid',
        'email',
        'profile',
    ];
```

- [ ] **Step 5: 관련 테스트 갱신**

`Iter/tests/unit/GooglePhotosAuthServiceTest.php`에서 `testAuthorizationUrlContainsScopesStateAndOfflineAccess` 메서드 전체를:

```php
    public function testAuthorizationUrlContainsScopesStateAndOfflineAccess(): void
    {
        $provider = new Google([
            'clientId' => 'test-client-id',
            'clientSecret' => 'test-secret',
            'redirectUri' => 'http://localhost:8080/auth/google/callback',
            'scopes' => [
                'openid',
                'email',
                'profile',
                'https://www.googleapis.com/auth/photospicker.mediaitems.readonly',
            ],
        ]);

        $url = $this->makeService($provider)->getAuthorizationUrl('state-token-abc');

        $this->assertStringContainsString('client_id=test-client-id', $url);
        $this->assertStringContainsString('state=state-token-abc', $url);
        $this->assertStringContainsString('access_type=offline', $url);
        $this->assertStringContainsString('prompt=consent', $url);
        $this->assertStringContainsString('photospicker.mediaitems.readonly', rawurldecode($url));
    }
```

로 교체:

```php
    public function testAuthorizationUrlContainsScopesStateAndOfflineAccess(): void
    {
        $provider = new Google([
            'clientId' => 'test-client-id',
            'clientSecret' => 'test-secret',
            'redirectUri' => 'http://localhost:8080/auth/google/callback',
            'scopes' => ['openid', 'email', 'profile'],
        ]);

        $url = $this->makeService($provider)->getAuthorizationUrl('state-token-abc');

        $this->assertStringContainsString('client_id=test-client-id', $url);
        $this->assertStringContainsString('state=state-token-abc', $url);
        $this->assertStringContainsString('access_type=offline', $url);
        $this->assertStringContainsString('prompt=consent', $url);
        $this->assertStringContainsString('profile', rawurldecode($url));
    }
```

- [ ] **Step 6: 전체 스위트로 삭제 누락 확인**

Run: `cd Iter && vendor/bin/phpunit`
Expected: 실패 0건(삭제된 클래스를 참조하는 코드가 남아있다면 여기서 클래스 없음 에러로 드러난다).

Run: `cd Iter && grep -rn "PhotoPickerService\|PickerController\|PhotoIngestService\|CurlMultiDownloader\|NativeExifExtractor\|ExifToolExtractor\|FallbackExifExtractor\|ExifGpsParser\|GoogleApiUsageTracker\|GoogleApiName" app/ tests/`
Expected: 결과 없음(전부 삭제 완료).

- [ ] **Step 7: `composer ci` 확인**

Run: `cd Iter && composer cs-fix && composer ci`
Expected: exit code 0.

- [ ] **Step 8: `Iter/CLAUDE.md` 갱신**

파일 전체를 아래로 교체:

```markdown
# Iter (Google Photos GPS 동선 시각화) 프로젝트 가이드

> 저장소 공통 규칙은 [`../CLAUDE.md`](../CLAUDE.md)에서 상속된다(PR 없이 직접 머지, CI/CD 없음·로컬 검증).
> 이 파일은 `Iter/` 프로젝트 고유의 스택·아키텍처·규칙만 다룬다.
> 전체 요구사항 명세는 [`docs/photo-gps-tracker-spec.md`](docs/photo-gps-tracker-spec.md)를 참고한다(단, GPS 획득 방식은
> 아래 핵심 전제에 따라 명세와 다르게 **Google Takeout zip 업로드**로 대체됐다).

## 프로젝트 개요

사용자가 Google Takeout에서 직접 내보낸 사진 zip을 업로드하면, 안의 `.json` 사이드카에서
GPS·촬영 시각을 추출해 지도 위에 **날짜별 이동 동선**(마커 + 경로선)으로 시각화하는 서비스.
프로젝트명 *Iter*(라틴어: 여정·경로)는 이 "동선"을 뜻한다.

### 핵심 전제 (반드시 숙지)

Google Photos Picker API·Library API 는 **다운로드 원본에서 GPS EXIF 를 의도적으로 제거한다**
(Google 공식 문서: "download the image retaining all the Exif metadata except the location
metadata"). 즉 Picker로 사진을 선택해 원본을 다운로드하는 방식으로는 GPS 를 절대 얻을 수 없다.
Google 이 GPS 를 원본 그대로 제공하는 유일한 경로는 **Google Takeout**(사용자가 직접 요청하는
비동기 벌크 내보내기) 이며, Takeout 은 사진 파일 자체에서도 GPS 를 제거하지만 `.json` 사이드카
파일의 `geoData`(또는 `geoDataExif`) 필드에 별도로 보존한다. 이 프로젝트는 사용자가 Takeout 에서
직접 내보낸 zip 을 업로드하는 방식으로 이 제약을 우회한다.

## 기술 스택

- **백엔드**: PHP 8.2+ / CodeIgniter 4
- **인증**: Google OAuth2 — 스코프 `openid`·`email`·`profile`(사용자 식별용, Photos API 는 더 호출하지 않음)
- **GPS 획득**: 사용자가 `takeout.google.com`에서 직접 내보낸 zip 업로드(`POST /takeout/upload`) →
  `ZipArchive`로 압축 해제 → `.json` 사이드카(`geoData`/`geoDataExif`) 파싱
- **DB**: MySQL
- **지도 시각화**: Leaflet.js + OpenStreetMap (날짜별 색상 구분 마커·경로선)

이 프로젝트의 PHP(CodeIgniter 4) 코드는 부모 저장소들의 전역 PHP 규칙
([`~/.claude/rules/code-style.md`](~/.claude/rules/code-style.md),
[`~/.claude/rules/security.md`](~/.claude/rules/security.md),
[`~/.claude/rules/testing.md`](~/.claude/rules/testing.md),
[`~/.claude/rules/api-design.md`](~/.claude/rules/api-design.md))을 그대로 따른다.

## 아키텍처 — 서비스 클래스 경계

| 서비스 | 책임 |
|--------|------|
| `GooglePhotosAuthService` | OAuth2 로그인/콜백/토큰 발급·갱신(사용자 식별용) |
| `TakeoutIngestService` | zip 압축 해제 + JSON 사이드카 파싱 + 사진 매칭 + 이상치 필터 (핵심 로직) |
| `RouteVisualizationService` | 날짜별 동선 조합·지도 응답 데이터 생성 |

- `TakeoutIngestService`는 업로드된 zip 의 로컬 경로를 입력받아 `PhotoLocation[]`을 출력하는
  순수 처리 서비스다(HTTP 요청 컨텍스트 비의존). 200장 상한, 동기 처리(큐 인프라 없음).
- 데이터 접근은 CI4 Model(`model(XxxModel::class)`) 경유. 비즈니스 로직은 Controller가 아닌 Service에 캡슐화(Controller는 얇게).

## 데이터 · 저장 정책

- 테이블: `photo_locations`(좌표·시간·`source_item_id`·`thumbnail_path`), `oauth_tokens`(refresh/access 토큰 **암호화** 저장).
- `source_item_id`는 Google mediaItemId 가 아니라 **업로드된 zip 안 사진 파일명**이다(사용자당 유니크, 재업로드 idempotency 보장).
- **원본 이미지 파일은 저장하지 않는다.** zip 압축 해제 후 처리에 쓴 임시 디렉터리는 처리 완료 즉시 통째로 삭제한다.
- **예외 — 가로 300px 썸네일만 보관**: 좌표 추출 성공 시 원본을 폐기하기 전에 가로 300px 썸네일을 생성해 `writable/uploads/thumbnails/`에 저장하고, 경로를 `photo_locations.thumbnail_path`에 함께 기록한다. 썸네일은 지도 미리보기 표시용으로, 원본과 달리 재조회 없이 즉시 서빙 가능해야 하므로 **이것만 캐싱 예외**로 둔다.
- 좌표 이상치(예: 직전 지점 대비 시속 200km 초과)는 필터링해 지도 잡음을 제거한다.
- 업로드 zip 자체도 처리 완료(성공·실패 무관) 즉시 삭제한다.

## 로컬 검증

> ⚠️ 명세 8절은 GitHub Actions CI/CD 통합을 포함하지만, **이 모노레포는 CI/CD를 두지 않는다**
> ([`../CLAUDE.md`](../CLAUDE.md)). 명세의 CI/CD 항목은 아래 **로컬 검증**으로 대체한다.

- CI/CD가 없으므로 머지 전 아래를 **로컬에서** 직접 실행해 확인한다.
  - `composer ci`(CS Fixer → PHPStan → PHPUnit). `composer check`는 CS Fixer를 빠뜨리므로 사용하지 않는다.
  - JSON 사이드카 파싱·이상치 필터링은 순수 로직이므로 **PHPUnit 단위 테스트로 반드시 커버**한다(외부 의존성 Mock).
- 런타임 표면(OAuth 콜백, zip 업로드, 지도 API 엔드포인트)이 있는 변경은 테스트만으로 끝내지 않고 실제 구동까지 확인한다. zip 업로드의 "성공 경로"(실제 파일 이동)는 PHP `is_uploaded_file()` 제약으로 자동화 테스트가 불가능해 **실제 브라우저로 수동 확인**한다.

## 보안 유의사항

- OAuth **refresh token은 암호화**해 `oauth_tokens` 테이블에 저장(AES 권장). 응답·로그에 토큰을 노출하지 않는다.
- 시크릿(Google Client ID/Secret, 암호화 키)은 `.env`에서만 관리 — 코드 하드코딩 금지.
- **레이트 리밋**을 적용: zip 업로드는 무거운 동기 처리이므로 사용자당 시간당 업로드 횟수를 제한한다(CI4 필터, `SessionRateLimitFilter` 재사용).
- 업로드 zip 크기·개수(200장) 상한을 애플리케이션 레벨에서 강제해 남용을 방지한다.
```

- [ ] **Step 9: 커밋**

```bash
cd Iter
git fetch origin && git log --oneline dev..origin/dev
git add -A app/Config/Routes.php app/Config/Services.php app/Config/GoogleOAuth.php \
        tests/unit/GooglePhotosAuthServiceTest.php CLAUDE.md \
        app/Services/PhotoPickerService.php app/Controllers/PickerController.php \
        app/Services/PhotoIngestService.php app/Services/Ingest/ app/Services/GoogleApiUsageTracker.php \
        app/Enums/GoogleApiName.php \
        tests/unit/PhotoPickerServiceTest.php tests/feature/PickerControllerTest.php \
        tests/unit/PhotoIngestServiceTest.php tests/unit/CurlMultiDownloaderTest.php \
        tests/unit/NativeExifExtractorTest.php tests/unit/ExifToolExtractorTest.php \
        tests/unit/FallbackExifExtractorTest.php tests/unit/ExifGpsParserTest.php \
        tests/unit/GoogleApiUsageTrackerTest.php tests/_support/Ingest/
git status --short   # 의도한 파일만 스테이징됐는지 확인 후
git commit -m "🔥 remove: Picker/EXIF 파이프라인 전면 삭제 — GPS 미제공 API 한계 확인, Takeout 방식으로 전환"
```

---

### Task 3: `TakeoutMetadataParser` (순수 JSON 파서)

**Files:**
- Create: `Iter/app/Services/Ingest/TakeoutMetadataParser.php`
- Test: `Iter/tests/unit/TakeoutMetadataParserTest.php`

**Interfaces:**
- Consumes: `App\Services\Ingest\ExifLocation`(기존 DTO 재사용, `{lat, lng, takenAt}`).
- Produces: `TakeoutMetadataParser::parse(array $json): ?ExifLocation`. Task 5(`TakeoutIngestService`)가 이 메서드를 소비한다.

- [ ] **Step 1: 실패하는 테스트 작성**

`Iter/tests/unit/TakeoutMetadataParserTest.php` 신규 생성:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ingest\TakeoutMetadataParser;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TakeoutMetadataParserTest extends CIUnitTestCase
{
    private TakeoutMetadataParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new TakeoutMetadataParser();
    }

    public function testParsesGeoDataCoordinatesAndTimestamp(): void
    {
        $location = $this->parser->parse([
            'geoData' => ['latitude' => 37.5665, 'longitude' => 126.9780, 'altitude' => 10.0],
            'photoTakenTime' => ['timestamp' => '1563490529'],
        ]);

        $this->assertNotNull($location);
        $this->assertEqualsWithDelta(37.5665, $location->lat, 0.0001);
        $this->assertEqualsWithDelta(126.9780, $location->lng, 0.0001);
        $this->assertSame('2019-07-18 22:55:29', $location->takenAt);
    }

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

    public function testReturnsNullWhenBothGeoDataAndGeoDataExifAreZero(): void
    {
        $location = $this->parser->parse([
            'geoData' => ['latitude' => 0.0, 'longitude' => 0.0],
            'geoDataExif' => ['latitude' => 0.0, 'longitude' => 0.0],
            'photoTakenTime' => ['timestamp' => '1563490529'],
        ]);

        $this->assertNull($location);
    }

    public function testReturnsNullWhenGeoDataFieldsMissing(): void
    {
        $this->assertNull($this->parser->parse(['photoTakenTime' => ['timestamp' => '1563490529']]));
    }

    public function testTakenAtIsNullWhenTimestampMissing(): void
    {
        $location = $this->parser->parse([
            'geoData' => ['latitude' => 37.5665, 'longitude' => 126.9780],
        ]);

        $this->assertNotNull($location);
        $this->assertNull($location->takenAt);
    }

    public function testReturnsNullWhenLatitudeIsNonNumeric(): void
    {
        $location = $this->parser->parse([
            'geoData' => ['latitude' => 'invalid', 'longitude' => 126.9780],
        ]);

        $this->assertNull($location);
    }
}
```

- [ ] **Step 2: 테스트 실패 확인**

Run: `cd Iter && vendor/bin/phpunit tests/unit/TakeoutMetadataParserTest.php`
Expected: 클래스 없음 에러로 6건 전부 실패.

- [ ] **Step 3: 구현**

`Iter/app/Services/Ingest/TakeoutMetadataParser.php` 신규 생성:

```php
<?php

declare(strict_types=1);

namespace App\Services\Ingest;

/**
 * Google Takeout 사진 JSON 사이드카에서 GPS 좌표·촬영 시각을 뽑는 순수 파서.
 *
 * Takeout 은 위치 없음을 (0.0, 0.0) 으로 표현하며, geoData(보정값)와
 * geoDataExif(원본 EXIF 값) 두 필드를 모두 제공한다 — geoData 를 우선하고
 * 둘 다 0.0 이면 geoDataExif 로 폴백한다.
 */
class TakeoutMetadataParser
{
    /**
     * @param array<string, mixed> $json Takeout 사진 JSON 사이드카를 json_decode 한 배열
     */
    public function parse(array $json): ?ExifLocation
    {
        $coords = $this->coordinates($json, 'geoData') ?? $this->coordinates($json, 'geoDataExif');
        if ($coords === null) {
            return null;
        }

        return new ExifLocation($coords[0], $coords[1], $this->takenAt($json));
    }

    /**
     * @param array<string, mixed> $json
     *
     * @return array{0: float, 1: float}|null
     */
    private function coordinates(array $json, string $key): ?array
    {
        $section = $json[$key] ?? null;
        if (! is_array($section)) {
            return null;
        }

        $lat = $section['latitude'] ?? null;
        $lng = $section['longitude'] ?? null;
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return null;
        }

        $lat = (float) $lat;
        $lng = (float) $lng;

        if ($lat === 0.0 && $lng === 0.0) {
            return null; // Takeout 은 위치 없음을 (0.0, 0.0) 으로 표현한다.
        }

        return [$lat, $lng];
    }

    /**
     * photoTakenTime.timestamp(Unix epoch 초, 문자열) 를 "Y-m-d H:i:s" 로 변환한다. 없으면 null.
     *
     * @param array<string, mixed> $json
     */
    private function takenAt(array $json): ?string
    {
        $photoTakenTime = $json['photoTakenTime'] ?? null;
        $timestamp = is_array($photoTakenTime) ? ($photoTakenTime['timestamp'] ?? null) : null;

        if (! is_numeric($timestamp)) {
            return null;
        }

        return date('Y-m-d H:i:s', (int) $timestamp);
    }
}
```

- [ ] **Step 4: 테스트 통과 확인**

Run: `cd Iter && vendor/bin/phpunit tests/unit/TakeoutMetadataParserTest.php`
Expected: `OK (6 tests, ...)`.

- [ ] **Step 5: 커밋**

```bash
cd Iter
git fetch origin && git log --oneline dev..origin/dev
git add app/Services/Ingest/TakeoutMetadataParser.php tests/unit/TakeoutMetadataParserTest.php
git commit -m "✨ feat: TakeoutMetadataParser — Takeout JSON 사이드카 GPS·촬영시각 파싱(순수)"
```

---

### Task 4: `UploadedZipHandlerInterface` + `NativeUploadedZipHandler`

**Files:**
- Create: `Iter/app/Services/Ingest/UploadedZipHandlerInterface.php`
- Create: `Iter/app/Services/Ingest/NativeUploadedZipHandler.php`
- Test: `Iter/tests/unit/NativeUploadedZipHandlerTest.php`

**Interfaces:**
- Produces: `UploadedZipHandlerInterface::store(UploadedFile $file, string $destinationDir): string`(저장된 zip 로컬 경로 반환, 실패 시 `RuntimeException`). Task 6(`TakeoutController`)이 이 인터페이스를 주입받아 사용하고, 테스트에서는 mock으로 대체한다(실제 업로드 검증은 `is_uploaded_file()` 의존으로 PHPUnit에서 시뮬레이션 불가).

- [ ] **Step 1: 실패하는 테스트 작성**

`Iter/tests/unit/NativeUploadedZipHandlerTest.php` 신규 생성:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ingest\NativeUploadedZipHandler;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

/**
 * @internal
 */
final class NativeUploadedZipHandlerTest extends CIUnitTestCase
{
    public function testThrowsWhenFileIsNotAGenuineUpload(): void
    {
        // is_uploaded_file() 은 실제 HTTP 업로드가 아니면 항상 false 를 반환하므로
        // isValid() 가 false 가 되는 경로를 검증한다(성공 경로는 실제 브라우저로 확인).
        $file = new UploadedFile('/nonexistent/tmp/path', 'takeout.zip', 'application/zip', 100, UPLOAD_ERR_OK);

        $handler = new NativeUploadedZipHandler();

        $this->expectException(RuntimeException::class);
        $handler->store($file, sys_get_temp_dir());
    }
}
```

- [ ] **Step 2: 테스트 실패 확인**

Run: `cd Iter && vendor/bin/phpunit tests/unit/NativeUploadedZipHandlerTest.php`
Expected: 클래스 없음 에러로 실패.

- [ ] **Step 3: 인터페이스·구현 작성**

`Iter/app/Services/Ingest/UploadedZipHandlerInterface.php` 신규 생성:

```php
<?php

declare(strict_types=1);

namespace App\Services\Ingest;

use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * 업로드된 zip 파일을 검증하고 로컬 경로로 저장한다.
 *
 * CI4 UploadedFile::isValid()/move() 는 PHP is_uploaded_file() 에 의존해 PHPUnit
 * 테스트에서 성공 경로를 시뮬레이션할 수 없다 — 이 인터페이스로 분리해 컨트롤러
 * 테스트에서는 mock 으로 대체하고, 실제 업로드 성공 경로는 브라우저로 수동 확인한다.
 */
interface UploadedZipHandlerInterface
{
    /**
     * @return string 저장된 zip 파일의 로컬 절대경로
     */
    public function store(UploadedFile $file, string $destinationDir): string;
}
```

`Iter/app/Services/Ingest/NativeUploadedZipHandler.php` 신규 생성:

```php
<?php

declare(strict_types=1);

namespace App\Services\Ingest;

use CodeIgniter\HTTP\Files\UploadedFile;
use RuntimeException;

class NativeUploadedZipHandler implements UploadedZipHandlerInterface
{
    public function store(UploadedFile $file, string $destinationDir): string
    {
        if (! $file->isValid()) {
            throw new RuntimeException('업로드된 파일이 유효하지 않습니다: ' . $file->getErrorString());
        }

        $name = $file->getRandomName();
        $file->move($destinationDir, $name);

        return rtrim($destinationDir, '/') . '/' . $name;
    }
}
```

- [ ] **Step 4: 테스트 통과 확인**

Run: `cd Iter && vendor/bin/phpunit tests/unit/NativeUploadedZipHandlerTest.php`
Expected: `OK (1 test, ...)`.

- [ ] **Step 5: 커밋**

```bash
cd Iter
git fetch origin && git log --oneline dev..origin/dev
git add app/Services/Ingest/UploadedZipHandlerInterface.php \
        app/Services/Ingest/NativeUploadedZipHandler.php \
        tests/unit/NativeUploadedZipHandlerTest.php
git commit -m "✨ feat: UploadedZipHandlerInterface — 업로드 파일 검증·저장 추상화(테스트 가능하게 분리)"
```

---

### Task 5: `TakeoutIngestService`

**Files:**
- Create: `Iter/app/Services/TakeoutIngestService.php`
- Test: `Iter/tests/unit/TakeoutIngestServiceTest.php`

**Interfaces:**
- Consumes: `TakeoutMetadataParser::parse()`(Task 3), `ThumbnailGeneratorInterface::generate()`(기존, `App\Services\Ingest\ThumbnailGeneratorInterface`), `App\Services\Ingest\PhotoLocation`(기존 DTO).
- Produces: `TakeoutIngestService::ingest(string $zipPath): array{locations: list<PhotoLocation>, totalCandidates: int}`. Task 6(`TakeoutController`)이 이 메서드를 소비한다.

- [ ] **Step 1: 실패하는 테스트 작성**

`Iter/tests/unit/TakeoutIngestServiceTest.php` 신규 생성(실제 임시 zip을 만들어 검증 — `ZipArchive`/GD는 실제 I/O이므로 mock 없이 진짜 파일로 테스트):

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ingest\TakeoutMetadataParser;
use App\Services\Ingest\ThumbnailGeneratorInterface;
use App\Services\TakeoutIngestService;
use CodeIgniter\Test\CIUnitTestCase;
use ZipArchive;

/**
 * @internal
 */
final class TakeoutIngestServiceTest extends CIUnitTestCase
{
    /** @var list<string> 테스트가 만든 파일(정리 대상) */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    /**
     * width x height JPEG 바이트를 만든다(GD).
     */
    private function jpegBytes(int $width = 40, int $height = 40): string
    {
        $image = imagecreatetruecolor($width, $height);
        $path = tempnam(sys_get_temp_dir(), 'src_') . '.jpg';
        imagejpeg($image, $path);
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /**
     * entries: 파일명 => 내용(문자열) 맵으로 zip 을 만든다.
     *
     * @param array<string, string> $entries
     */
    private function makeZip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'takeout_') . '.zip';
        $this->tempFiles[] = $path;

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    private function geoJson(float $lat, float $lng, string $timestamp = '1563490529'): string
    {
        return (string) json_encode([
            'geoData' => ['latitude' => $lat, 'longitude' => $lng],
            'photoTakenTime' => ['timestamp' => $timestamp],
        ]);
    }

    public function testExtractsLocationsFromMatchedPhotoJsonPairs(): void
    {
        $zipPath = $this->makeZip([
            'photo1.jpg' => $this->jpegBytes(),
            'photo1.jpg.json' => $this->geoJson(37.5665, 126.9780, '1563490529'),
        ]);

        $service = new TakeoutIngestService(new TakeoutMetadataParser());
        $result = $service->ingest($zipPath);

        $this->assertCount(1, $result['locations']);
        $this->assertSame(1, $result['totalCandidates']);
        $this->assertSame('photo1.jpg', $result['locations'][0]->mediaItemId);
        $this->assertEqualsWithDelta(37.5665, $result['locations'][0]->lat, 0.0001);
        $this->assertSame('2019-07-18 22:55:29', $result['locations'][0]->takenAt);
    }

    public function testSkipsJsonWithoutMatchingPhoto(): void
    {
        $zipPath = $this->makeZip([
            'orphan.jpg.json' => $this->geoJson(37.5665, 126.9780),
        ]);

        $service = new TakeoutIngestService(new TakeoutMetadataParser());
        $result = $service->ingest($zipPath);

        $this->assertSame([], $result['locations']);
        $this->assertSame(1, $result['totalCandidates']);
    }

    public function testSkipsPhotosWithoutGpsInJson(): void
    {
        $zipPath = $this->makeZip([
            'photo1.jpg' => $this->jpegBytes(),
            'photo1.jpg.json' => (string) json_encode(['photoTakenTime' => ['timestamp' => '1563490529']]),
        ]);

        $service = new TakeoutIngestService(new TakeoutMetadataParser());
        $result = $service->ingest($zipPath);

        $this->assertSame([], $result['locations']);
    }

    public function testCapsAtMaxItemsButReportsTotalCandidates(): void
    {
        $entries = [];
        for ($i = 1; $i <= 3; $i++) {
            $entries["photo{$i}.jpg"] = $this->jpegBytes();
            $entries["photo{$i}.jpg.json"] = $this->geoJson(37.0 + $i, 127.0, (string) (1563490529 + $i));
        }
        $zipPath = $this->makeZip($entries);

        $service = new TakeoutIngestService(new TakeoutMetadataParser(), null, 200.0, maxItems: 2);
        $result = $service->ingest($zipPath);

        $this->assertCount(2, $result['locations']);
        $this->assertSame(3, $result['totalCandidates']);
    }

    public function testFiltersOutliersByImpossibleSpeed(): void
    {
        $zipPath = $this->makeZip([
            'seoul.jpg' => $this->jpegBytes(),
            'seoul.jpg.json' => $this->geoJson(37.5665, 126.9780, '1000000000'),
            'busan.jpg' => $this->jpegBytes(),
            // 서울 → 부산 ~325km 를 5분(300초)에 이동 = 비현실적 → 제외
            'busan.jpg.json' => $this->geoJson(35.1796, 129.0756, '1000000300'),
        ]);

        $service = new TakeoutIngestService(new TakeoutMetadataParser());
        $result = $service->ingest($zipPath);

        $this->assertCount(1, $result['locations']);
        $this->assertSame('seoul.jpg', $result['locations'][0]->mediaItemId);
    }

    public function testGeneratesThumbnailWhenGeneratorProvided(): void
    {
        $zipPath = $this->makeZip([
            'photo1.jpg' => $this->jpegBytes(),
            'photo1.jpg.json' => $this->geoJson(37.5665, 126.9780),
        ]);

        $thumbnailer = $this->createMock(ThumbnailGeneratorInterface::class);
        $thumbnailer->expects($this->once())
            ->method('generate')
            ->with($this->stringContains('photo1.jpg'), 'photo1.jpg')
            ->willReturn('/thumbs/photo1.jpg');

        $service = new TakeoutIngestService(new TakeoutMetadataParser(), $thumbnailer);
        $result = $service->ingest($zipPath);

        $this->assertSame('/thumbs/photo1.jpg', $result['locations'][0]->thumbnailPath);
    }

    public function testExtractedTempDirectoryIsRemovedAfterIngest(): void
    {
        $zipPath = $this->makeZip([
            'photo1.jpg' => $this->jpegBytes(),
            'photo1.jpg.json' => $this->geoJson(37.5665, 126.9780),
        ]);

        $service = new TakeoutIngestService(new TakeoutMetadataParser());
        $service->ingest($zipPath);

        $leftover = glob(WRITEPATH . 'uploads/takeout_*');
        $this->assertSame([], $leftover, '압축 해제 임시 디렉터리가 정리되지 않았습니다.');
    }
}
```

- [ ] **Step 2: 테스트 실패 확인**

Run: `cd Iter && vendor/bin/phpunit tests/unit/TakeoutIngestServiceTest.php`
Expected: 클래스 없음 에러로 전부 실패.

- [ ] **Step 3: 구현**

`Iter/app/Services/TakeoutIngestService.php` 신규 생성:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Ingest\PhotoLocation;
use App\Services\Ingest\TakeoutMetadataParser;
use App\Services\Ingest\ThumbnailGeneratorInterface;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

/**
 * 업로드된 Google Takeout zip 을 처리해 동선 좌표를 만드는 핵심 서비스.
 *
 * zip 압축 해제 → 사진 ↔ JSON 사이드카 매칭(정확히 일치하는 파일명만) → GPS·촬영시각
 * 파싱 → 200장 상한 → 이상치 필터. 임시 디렉터리는 처리 완료(성공·실패 무관) 즉시 삭제한다.
 */
class TakeoutIngestService
{
    private const DEFAULT_MAX_ITEMS = 200;
    private const EARTH_RADIUS_KM = 6371.0;

    public function __construct(
        private readonly TakeoutMetadataParser $parser,
        private readonly ?ThumbnailGeneratorInterface $thumbnailGenerator = null,
        private readonly float $maxSpeedKmh = 200.0,
        private readonly int $maxItems = self::DEFAULT_MAX_ITEMS,
    ) {
    }

    /**
     * @return array{locations: list<PhotoLocation>, totalCandidates: int}
     */
    public function ingest(string $zipPath): array
    {
        $extractDir = WRITEPATH . 'uploads/takeout_' . uniqid('', true);
        if (! mkdir($extractDir, 0755, true) && ! is_dir($extractDir)) {
            throw new RuntimeException('임시 디렉토리를 만들 수 없습니다: ' . $extractDir);
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException('zip 파일을 열 수 없습니다: ' . $zipPath);
            }
            $zip->extractTo($extractDir);
            $zip->close();

            return $this->processExtracted($extractDir);
        } finally {
            $this->removeDirectory($extractDir);
        }
    }

    /**
     * @return array{locations: list<PhotoLocation>, totalCandidates: int}
     */
    private function processExtracted(string $dir): array
    {
        $jsonFiles = $this->findJsonFiles($dir);
        $totalCandidates = count($jsonFiles);

        $locations = [];
        foreach (array_slice($jsonFiles, 0, $this->maxItems) as $jsonPath) {
            $mediaPath = pathinfo($jsonPath, PATHINFO_DIRNAME) . '/' . pathinfo($jsonPath, PATHINFO_FILENAME);
            if (! is_file($mediaPath)) {
                continue; // 짝이 되는 사진 파일 없음
            }

            $decoded = json_decode((string) file_get_contents($jsonPath), true);
            if (! is_array($decoded)) {
                continue;
            }

            $parsed = $this->parser->parse($decoded);
            if ($parsed === null || $parsed->takenAt === null) {
                continue;
            }

            $sourceItemId = basename($mediaPath);
            $thumbnailPath = $this->thumbnailGenerator?->generate($mediaPath, $sourceItemId);

            $locations[] = new PhotoLocation($sourceItemId, $parsed->lat, $parsed->lng, $parsed->takenAt, $thumbnailPath);
        }

        return [
            'locations' => $this->filterOutliers($locations),
            'totalCandidates' => $totalCandidates,
        ];
    }

    /**
     * @return list<string>
     */
    private function findJsonFiles(string $dir): array
    {
        $found = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && strtolower($fileInfo->getExtension()) === 'json') {
                $found[] = $fileInfo->getPathname();
            }
        }

        sort($found);

        return $found;
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }

    /**
     * 직전 유효 지점 대비 비현실적 이동 속도(기본 200km/h 초과)인 지점을 제외한다.
     *
     * @param list<PhotoLocation> $locations
     *
     * @return list<PhotoLocation>
     */
    private function filterOutliers(array $locations): array
    {
        if (count($locations) <= 1) {
            return $locations;
        }

        usort($locations, static fn (PhotoLocation $a, PhotoLocation $b): int => strcmp($a->takenAt, $b->takenAt));

        $kept = [$locations[0]];
        $previous = $locations[0];

        foreach (array_slice($locations, 1) as $current) {
            if ($this->isReachable($previous, $current)) {
                $kept[] = $current;
                $previous = $current;
            }
        }

        return $kept;
    }

    private function isReachable(PhotoLocation $from, PhotoLocation $to): bool
    {
        $hours = (strtotime($to->takenAt) - strtotime($from->takenAt)) / 3600;
        $distanceKm = $this->haversineKm($from->lat, $from->lng, $to->lat, $to->lng);

        if ($hours <= 0.0) {
            return $distanceKm < 0.001;
        }

        return ($distanceKm / $hours) <= $this->maxSpeedKmh;
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * asin(min(1.0, sqrt($a)));
    }
}
```

- [ ] **Step 4: 테스트 통과 확인**

Run: `cd Iter && vendor/bin/phpunit tests/unit/TakeoutIngestServiceTest.php`
Expected: `OK (7 tests, ...)`.

- [ ] **Step 5: 커밋**

```bash
cd Iter
git fetch origin && git log --oneline dev..origin/dev
git add app/Services/TakeoutIngestService.php tests/unit/TakeoutIngestServiceTest.php
git commit -m "✨ feat: TakeoutIngestService — zip 압축 해제·JSON 매칭·이상치 필터(핵심 로직)"
```

---

### Task 6: `TakeoutController` + 라우트 + `Config\Services` 팩토리

**Files:**
- Create: `Iter/app/Controllers/TakeoutController.php`
- Modify: `Iter/app/Config/Routes.php`
- Modify: `Iter/app/Config/Services.php`
- Modify: `Iter/app/Config/Filters.php`(선택 — 이미 `sessionRateLimit` 별칭이 있으면 무변경)
- Test: `Iter/tests/feature/TakeoutControllerTest.php`

**Interfaces:**
- Consumes: `UploadedZipHandlerInterface::store()`(Task 4), `TakeoutIngestService::ingest()`(Task 5, 반환 `array{locations: list<PhotoLocation>, totalCandidates: int}`), `PhotoLocationModel::saveMany()`(Task 1, 기존), `BaseController::currentUserId()`(기존).
- Produces: `POST /takeout/upload` — 로그인 필요(401), zip 아니면 422, 처리 실패 502, 성공 시 `{saved: int, totalCandidates: int}`(200). Task 7(홈 화면 UI)이 이 URL을 소비한다.

- [ ] **Step 1: 실패하는 테스트 작성**

`Iter/tests/feature/TakeoutControllerTest.php` 신규 생성:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UserModel;
use App\Services\Ingest\PhotoLocation;
use App\Services\Ingest\UploadedZipHandlerInterface;
use App\Services\TakeoutIngestService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * @internal
 */
final class TakeoutControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $refresh = true;
    protected $namespace = 'App';

    public function testUploadRequiresLogin(): void
    {
        $result = $this->post('takeout/upload');

        $result->assertStatus(401);
    }

    public function testUploadRejectsMissingFile(): void
    {
        $userId = (new UserModel())->upsertByGoogleSub('sub-takeout-1', 't1@example.com', 'T1');

        $result = $this->withSession(['user_id' => $userId])->post('takeout/upload');

        $result->assertStatus(422);
    }

    public function testUploadSavesLocationsAndReturnsCount(): void
    {
        $userId = (new UserModel())->upsertByGoogleSub('sub-takeout-2', 't2@example.com', 'T2');

        $handler = $this->createMock(UploadedZipHandlerInterface::class);
        $handler->method('store')->willReturn('/fake/takeout.zip');
        Services::injectMock('uploadedZipHandler', $handler);

        $ingest = $this->createMock(TakeoutIngestService::class);
        $ingest->expects($this->once())
            ->method('ingest')
            ->with('/fake/takeout.zip')
            ->willReturn([
                'locations' => [new PhotoLocation('photo1.jpg', 37.5665, 126.9780, '2024-03-15 09:00:00')],
                'totalCandidates' => 1,
            ]);
        Services::injectMock('takeoutIngest', $ingest);

        // 컨트롤러가 $this->request->getFile('file') 로 실제 UploadedFile 을 요구하므로
        // superglobals 에 직접 파일 정보를 주입한다(실제 tmp_name 은 진짜 파일을 가리켜야 함).
        $fakeUpload = tempnam(sys_get_temp_dir(), 'upload_') . '.zip';
        file_put_contents($fakeUpload, 'zip-bytes');
        service('superglobals')->setFilesArray([
            'file' => [
                'name' => 'takeout.zip',
                'type' => 'application/zip',
                'tmp_name' => $fakeUpload,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($fakeUpload),
            ],
        ]);

        try {
            $result = $this->withSession(['user_id' => $userId])->post('takeout/upload');

            $result->assertStatus(200);
            $data = json_decode($result->getJSON() ?? '', true);
            $this->assertSame(1, $data['saved']);
            $this->assertSame(1, $data['totalCandidates']);
            $this->seeInDatabase('photo_locations', [
                'user_id' => $userId,
                'source_item_id' => 'photo1.jpg',
            ]);
        } finally {
            service('superglobals')->setFilesArray([]);
            if (is_file($fakeUpload)) {
                unlink($fakeUpload);
            }
        }
    }
}
```

- [ ] **Step 2: 테스트 실패 확인**

Run: `cd Iter && vendor/bin/phpunit tests/feature/TakeoutControllerTest.php`
Expected: 라우트 없음(404) 또는 서비스 없음 에러로 3건 전부 실패.

- [ ] **Step 3: `Config\Services`에 팩토리 추가**

`Iter/app/Config/Services.php`에서 `use` 블록:

```php
use App\Models\OAuthTokenModel;
use App\Models\PhotoLocationModel;
use App\Models\UserModel;
use App\Services\GooglePhotosAuthService;
use App\Services\RouteVisualizationService;
use CodeIgniter\Config\BaseService;
use League\OAuth2\Client\Provider\Google;
```

를 다음으로 교체:

```php
use App\Models\OAuthTokenModel;
use App\Models\PhotoLocationModel;
use App\Models\UserModel;
use App\Services\GooglePhotosAuthService;
use App\Services\Ingest\GdThumbnailGenerator;
use App\Services\Ingest\NativeUploadedZipHandler;
use App\Services\Ingest\TakeoutMetadataParser;
use App\Services\Ingest\UploadedZipHandlerInterface;
use App\Services\RouteVisualizationService;
use App\Services\TakeoutIngestService;
use CodeIgniter\Config\BaseService;
use League\OAuth2\Client\Provider\Google;
```

그리고 `routeVisualization()` 메서드 뒤(클래스 닫는 `}` 직전)에 아래 두 메서드를 추가:

```php

    /**
     * 업로드된 zip 파일 검증·저장 서비스.
     */
    public static function uploadedZipHandler(bool $getShared = true): UploadedZipHandlerInterface
    {
        if ($getShared) {
            return static::getSharedInstance('uploadedZipHandler');
        }

        return new NativeUploadedZipHandler();
    }

    /**
     * Google Takeout zip → 동선 좌표 적재 서비스.
     *
     * JSON 사이드카 파서 + 300px 썸네일 생성기를 조립한다.
     */
    public static function takeoutIngest(bool $getShared = true): TakeoutIngestService
    {
        if ($getShared) {
            return static::getSharedInstance('takeoutIngest');
        }

        return new TakeoutIngestService(
            new TakeoutMetadataParser(),
            new GdThumbnailGenerator(WRITEPATH . 'uploads/thumbnails'),
        );
    }
```

- [ ] **Step 4: 라우트 추가**

`Iter/app/Config/Routes.php`에서 아래 줄:

```php
// 동선 시각화
$routes->get('routes', 'RouteController::data');
$routes->get('map', 'RouteController::map');
```

바로 앞에 추가:

```php
// Google Takeout zip 업로드
$routes->post('takeout/upload', 'TakeoutController::upload', ['filter' => 'sessionRateLimit']);

```

- [ ] **Step 5: 컨트롤러 구현**

`Iter/app/Controllers/TakeoutController.php` 신규 생성:

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\PhotoLocationModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Google Takeout zip 업로드 컨트롤러.
 *
 * 로직은 UploadedZipHandlerInterface·TakeoutIngestService 에 위임하고,
 * 컨트롤러는 인증 가드·검증·응답만 담당한다.
 */
class TakeoutController extends BaseController
{
    private const MAX_UPLOAD_BYTES = 500 * 1024 * 1024; // 500MB

    /**
     * zip 업로드 — 압축 해제해 동선 좌표를 추출·저장한다(POST /takeout/upload).
     */
    public function upload(): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => '로그인이 필요합니다.']);
        }

        $file = $this->request->getFile('file');
        if ($file === null || strtolower($file->getClientExtension()) !== 'zip') {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'zip 파일만 업로드할 수 있습니다.']);
        }

        if ($file->getSize() === null || $file->getSize() > self::MAX_UPLOAD_BYTES) {
            return $this->response->setStatusCode(413)->setJSON(['error' => '파일이 너무 큽니다(최대 500MB).']);
        }

        try {
            $zipPath = service('uploadedZipHandler')->store($file, WRITEPATH . 'uploads');
        } catch (Throwable $e) {
            log_message('error', 'Takeout zip 업로드 실패: {msg}', ['msg' => $e->getMessage()]);

            return $this->response->setStatusCode(422)->setJSON(['error' => '업로드된 파일을 처리할 수 없습니다.']);
        }

        try {
            $result = service('takeoutIngest')->ingest($zipPath);
        } catch (Throwable $e) {
            log_message('error', 'Takeout 처리 실패: {msg}', ['msg' => $e->getMessage()]);

            return $this->response->setStatusCode(502)->setJSON(['error' => 'zip 처리에 실패했습니다.']);
        } finally {
            if (is_file($zipPath)) {
                unlink($zipPath);
            }
        }

        $saved = model(PhotoLocationModel::class)->saveMany($userId, $result['locations']);

        return $this->response->setJSON([
            'saved' => $saved,
            'totalCandidates' => $result['totalCandidates'],
        ]);
    }
}
```

- [ ] **Step 6: 테스트 통과 확인**

Run: `cd Iter && vendor/bin/phpunit tests/feature/TakeoutControllerTest.php`
Expected: `OK (3 tests, ...)`.

만약 `testUploadSavesLocationsAndReturnsCount`가 `service('superglobals')->setFilesArray()` 관련 에러로 실패한다면, `Config\Filters.php`의 `sessionRateLimit` 필터가 throttler 캐시 문제를 일으키는지 먼저 배제하고(`cache()->clean();`을 `setUp()`에 추가), 그래도 실패하면 `$file->getClientExtension()`이 기대한 값을 돌려주는지 `var_dump`로 직접 확인한다(RED 상태에서 원인 파악 후 진행 — 추측성 수정 금지, `superpowers:systematic-debugging` 절차를 따른다).

- [ ] **Step 7: 커밋**

```bash
cd Iter
git fetch origin && git log --oneline dev..origin/dev
git add app/Controllers/TakeoutController.php app/Config/Routes.php app/Config/Services.php \
        tests/feature/TakeoutControllerTest.php
git commit -m "✨ feat: TakeoutController — POST /takeout/upload(zip 업로드→적재→저장)"
```

---

### Task 7: 홈 화면 UI — Picker 흐름을 zip 업로드 폼으로 교체

**Files:**
- Modify: `Iter/app/Controllers/Home.php`
- Modify: `Iter/app/Views/home.php`
- Modify: `Iter/tests/feature/HomeControllerTest.php`

**Interfaces:**
- Consumes: `POST /takeout/upload`(Task 6).
- Produces: 없음(최종 사용자 화면).

- [ ] **Step 1: 실패하는 테스트로 갱신**

`Iter/tests/feature/HomeControllerTest.php`의 `testShowsMenuAndPickerButtonWhenLoggedIn` 메서드를 찾아 아래로 교체(메서드명도 변경):

```php
    public function testShowsMenuAndUploadFormWhenLoggedIn(): void
    {
        $userId = (new UserModel())->upsertByGoogleSub('sub-home', 'home@example.com', 'Home');

        $result = $this->withSession(['user_id' => $userId])->get('/');

        $result->assertStatus(200);
        $body = $this->decodedBody($result);
        $this->assertStringContainsString('지도 보기', $body);
        $this->assertStringContainsString('/map', $body);
        $this->assertStringContainsString('로그아웃', $body);
        $this->assertStringContainsString('/auth/logout', $body);
        $this->assertStringContainsString('id="takeout-form"', $body);
        $this->assertStringContainsString('/takeout/upload', $body);
        $this->assertStringContainsString('takeout.google.com', $body);
    }
```

(`testShowsLoginButtonWhenNotLoggedIn`·`decodedBody()` 헬퍼는 무변경.)

- [ ] **Step 2: 테스트 실패 확인**

Run: `cd Iter && vendor/bin/phpunit tests/feature/HomeControllerTest.php`
Expected: `id="takeout-form"`·`/takeout/upload` 문자열이 기존 뷰(Picker 버튼)에 없어 실패.

- [ ] **Step 3: `Home::index()` 갱신**

`Iter/app/Controllers/Home.php` 전체를 아래로 교체:

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

class Home extends BaseController
{
    public function index(): string
    {
        helper('url');

        return view('home', [
            'userId' => $this->currentUserId(),
            'loginUrl' => site_url('auth/google'),
            'logoutUrl' => site_url('auth/logout'),
            'mapUrl' => site_url('map'),
            'uploadUrl' => site_url('takeout/upload'),
        ]);
    }
}
```

- [ ] **Step 4: `home.php` 뷰 교체**

`Iter/app/Views/home.php` 전체를 아래로 교체(기존 랜딩 섹션·법적 링크·스타일은 유지하고, 로그인 시 메인 영역만 Picker JS → 업로드 폼으로 교체):

```php
<?php

declare(strict_types=1);

/**
 * 홈 화면 — 비로그인 시 랜딩, 로그인 시 상단 메뉴 + Takeout zip 업로드 폼.
 *
 * @var int|null $userId    로그인 사용자 id(비로그인 시 null)
 * @var string   $loginUrl
 * @var string   $logoutUrl
 * @var string   $mapUrl
 * @var string   $uploadUrl
 */
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Google Takeout으로 내보낸 사진의 GPS·촬영 시각을 추출해 날짜별 이동 동선을 지도 위에 시각화하는 서비스입니다.">
    <meta property="og:site_name" content="Iter">
    <title>Iter</title>
    <style>
        html, body { margin: 0; font-family: system-ui, sans-serif; color: #222; }
        nav { display: flex; gap: 16px; padding: 12px 20px; border-bottom: 1px solid #ddd; align-items: center; }
        nav a { color: #222; text-decoration: none; font-size: 14px; }
        nav a:hover { text-decoration: underline; }
        nav .spacer { flex: 1; }
        nav .legal { display: flex; gap: 16px; }
        nav .legal a { color: #777; font-size: 13px; }
        main { padding: 40px 20px; max-width: 480px; margin: 0 auto; text-align: center; }
        .btn {
            display: inline-block; padding: 10px 20px; border-radius: 6px; border: none;
            background: #1a73e8; color: #fff; font-size: 15px; cursor: pointer; text-decoration: none;
        }
        .btn:disabled { background: #9db8e8; cursor: not-allowed; }
        #status { margin-top: 20px; font-size: 14px; color: #555; }
        #error { margin-top: 20px; font-size: 14px; color: #c0392b; }
        .legal-footer { margin-top: 24px; font-size: 13px; }
        .legal-footer a { color: #777; }
        .help { margin-top: 16px; font-size: 13px; color: #666; text-align: left; line-height: 1.6; }
        .help a { color: #1a73e8; }
        input[type="file"] { margin-top: 20px; }

        main.landing { max-width: 560px; }
        .landing .lead { font-size: 16px; color: #444; margin-bottom: 8px; }
        .landing .sub { font-size: 14px; color: #777; margin-bottom: 32px; }
        .landing .btn { padding: 12px 28px; font-size: 16px; }
        .steps {
            list-style: none; margin: 32px 0; padding: 0;
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; text-align: left;
        }
        .steps li { background: #f7f8fa; border-radius: 10px; padding: 16px; }
        .steps .num {
            display: inline-flex; align-items: center; justify-content: center;
            width: 22px; height: 22px; border-radius: 50%;
            background: #1a73e8; color: #fff; font-size: 12px; font-weight: 700;
            margin-bottom: 8px;
        }
        .steps .title { font-size: 14px; font-weight: 600; margin-bottom: 4px; }
        .steps .desc { font-size: 13px; color: #666; line-height: 1.5; }
        .privacy-note {
            margin-top: 28px; padding: 14px 16px; border-radius: 10px;
            background: #eef4ff; color: #3a5a9c; font-size: 13px; text-align: left; line-height: 1.6;
        }
        @media (max-width: 520px) {
            .steps { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<?php if ($userId === null): ?>
    <main class="landing">
        <h1>Iter</h1>
        <p class="lead">내가 찍은 사진 속 GPS로, 여행의 동선을 지도 위에 그려드립니다.</p>
        <p class="sub">Google Takeout에서 내보낸 사진 zip을 업로드하면, 언제 어디를 다녀왔는지 날짜별 경로로 한눈에 확인할 수 있어요.</p>

        <ul class="steps">
            <li>
                <span class="num">1</span>
                <div class="title">Takeout 내보내기</div>
                <div class="desc">Google 계정에서 사진(Google Photos)만 선택해 zip으로 내보냅니다.</div>
            </li>
            <li>
                <span class="num">2</span>
                <div class="title">zip 업로드</div>
                <div class="desc">받은 zip 파일을 그대로 업로드하면 위치·시간을 자동으로 읽어옵니다.</div>
            </li>
            <li>
                <span class="num">3</span>
                <div class="title">동선 확인</div>
                <div class="desc">날짜별 색으로 구분된 마커와 경로선으로 지도 위에서 동선을 확인합니다.</div>
            </li>
        </ul>

        <a class="btn" href="<?= esc($loginUrl, 'attr') ?>">Google로 로그인</a>

        <p class="privacy-note">
            업로드된 zip은 위치·시각 정보 추출이 끝나는 즉시 서버에서 삭제됩니다.
            원본 사진 파일은 저장하지 않습니다.
        </p>

        <p class="legal-footer">
            <a href="/privacy-policy.html">개인정보처리방침</a> ·
            <a href="/terms-of-service.html">서비스 이용약관</a>
        </p>
    </main>
<?php else: ?>
    <nav>
        <a href="/">홈</a>
        <a href="<?= esc($mapUrl, 'attr') ?>">지도 보기</a>
        <a href="<?= esc($logoutUrl, 'attr') ?>">로그아웃</a>
        <span class="spacer"></span>
        <span class="legal">
            <a href="/privacy-policy.html">개인정보처리방침</a>
            <a href="/terms-of-service.html">서비스 이용약관</a>
        </span>
    </nav>
    <main id="takeout-flow">
        <h1>사진 가져오기</h1>
        <p class="help">
            <a href="https://takeout.google.com" target="_blank" rel="noopener">takeout.google.com</a>에서
            "Google Photos"만 선택해 내보낸 뒤, 받은 zip 파일을 업로드하세요.
        </p>
        <form id="takeout-form" data-upload-url="<?= esc($uploadUrl, 'attr') ?>" data-map-url="<?= esc($mapUrl, 'attr') ?>">
            <input type="file" id="takeout-file" name="file" accept=".zip">
            <div>
                <button type="submit" id="upload-btn" class="btn">업로드</button>
            </div>
        </form>
        <div id="status"></div>
        <div id="error"></div>
    </main>

    <script>
        (function () {
            var form = document.getElementById('takeout-form');
            var fileInput = document.getElementById('takeout-file');
            var uploadBtn = document.getElementById('upload-btn');
            var statusEl = document.getElementById('status');
            var errorEl = document.getElementById('error');
            var uploadUrl = form.dataset.uploadUrl;
            var mapUrl = form.dataset.mapUrl;

            form.addEventListener('submit', function (evt) {
                evt.preventDefault();

                if (!fileInput.files || fileInput.files.length === 0) {
                    showError('zip 파일을 선택해주세요');
                    return;
                }

                reset();
                setBusy(true);
                statusEl.textContent = '처리 중...';

                var body = new FormData();
                body.append('file', fileInput.files[0]);

                fetch(uploadUrl, { method: 'POST', body: body, headers: { Accept: 'application/json' } })
                    .then(handleJson)
                    .then(function (data) {
                        var message = data.saved + '장 저장됨';
                        if (data.totalCandidates > data.saved) {
                            message += '(' + data.totalCandidates + '장 중 상한까지만 처리됨)';
                        }
                        statusEl.textContent = message;

                        var link = document.createElement('a');
                        link.className = 'btn';
                        link.href = mapUrl;
                        link.textContent = '지도에서 보기';
                        link.style.marginTop = '12px';
                        link.style.display = 'inline-block';
                        document.getElementById('takeout-flow').appendChild(link);
                        setBusy(false);
                    })
                    .catch(onError);
            });

            function handleJson(res) {
                if (!res.ok) {
                    return res.json().catch(function () { return {}; }).then(function (body) {
                        var err = new Error(body.error || ('요청 실패(' + res.status + ')'));
                        err.status = res.status;
                        throw err;
                    });
                }
                return res.json();
            }

            function onError(err) {
                showError((err && err.message) || '오류가 발생했습니다', err && err.status === 401);
            }

            function showError(message, isAuthError) {
                errorEl.textContent = message;
                errorEl.appendChild(document.createElement('br'));

                if (isAuthError) {
                    var loginLink = document.createElement('a');
                    loginLink.className = 'btn';
                    loginLink.href = '<?= esc($loginUrl, 'js') ?>';
                    loginLink.textContent = '다시 로그인하기';
                    errorEl.appendChild(loginLink);
                } else {
                    var retry = document.createElement('button');
                    retry.className = 'btn';
                    retry.type = 'button';
                    retry.textContent = '다시 시도';
                    retry.addEventListener('click', reset);
                    errorEl.appendChild(retry);
                }
                setBusy(false);
            }

            function setBusy(busy) {
                uploadBtn.disabled = busy;
            }

            function reset() {
                statusEl.textContent = '';
                errorEl.innerHTML = '';
                setBusy(false);
            }
        })();
    </script>
<?php endif; ?>
</body>
</html>
```

- [ ] **Step 5: 테스트 통과 확인**

Run: `cd Iter && vendor/bin/phpunit tests/feature/HomeControllerTest.php`
Expected: `OK (2 tests, ...)`.

- [ ] **Step 6: 전체 스위트 확인**

Run: `cd Iter && vendor/bin/phpunit`
Expected: 실패 0건.

- [ ] **Step 7: 커밋**

```bash
cd Iter
git fetch origin && git log --oneline dev..origin/dev
git add app/Controllers/Home.php app/Views/home.php tests/feature/HomeControllerTest.php
git commit -m "✨ feat: 홈 화면 — Picker 흐름을 Takeout zip 업로드 폼으로 교체"
```

---

### Task 8: 전체 검증

**Files:** 없음(검증 전용).

**Interfaces:** 없음.

- [ ] **Step 1: 전체 스위트 + 게이트**

Run: `cd Iter && composer cs-fix && composer ci`
Expected: exit code 0. `vendor/bin/phpunit` 로도 별도 확인: 실패 0건, 삭제된 Picker 관련 클래스 참조로 인한 에러 없음.

- [ ] **Step 2: 죽은 참조 재확인**

Run: `cd Iter && grep -rn "photospicker\|PickerService\|PickerController" app/ --include="*.php"`
Expected: 결과 없음(스코프·클래스 참조 완전히 제거됨 확인).

- [ ] **Step 3: 라우트 등록 확인**

Run: `cd Iter && php spark routes | grep -E "takeout/upload|^\| GET.*\/ |auth/logout|routes |map "`
Expected: `POST takeout/upload → TakeoutController::upload` 및 기존 라우트들이 정상 표시.

- [ ] **Step 4: 실서버 스모크**

```bash
cd Iter
php spark serve --port 8270 > /tmp/iter-takeout-smoke.log 2>&1 &
sleep 3
curl -s http://localhost:8270/ | grep -o 'takeout.google.com'
curl -s -o /dev/null -w "%{http_code}\n" -X POST http://localhost:8270/takeout/upload   # 비로그인 401 기대
grep -iE "fatal|exception" /tmp/iter-takeout-smoke.log | head -5   # 부팅 에러 없어야 함
kill %1 2>/dev/null
```

Expected: 랜딩 페이지에 `takeout.google.com` 링크 존재, `/takeout/upload` 비로그인 401, 부팅 에러 없음.

- [ ] **Step 5: 실제 브라우저로 업로드 흐름 수동 확인 (사용자 검증)**

자동화 불가능한 부분(실제 `is_uploaded_file()` 경로)이므로 개발자가 직접 확인한다:
1. `takeout.google.com`에서 Google Photos 일부만(테스트용으로 소량 앨범/기간 선택) 내보내 zip을 받는다.
2. `/`에서 로그인 → zip 업로드 → "N장 저장됨" 확인.
3. "지도에서 보기" → `/map`에서 실제 마커가 찍히는지 확인.
4. 200장 초과 zip으로도 한 번 시도해 "상한까지만 처리됨" 안내가 뜨는지 확인(선택, 큰 zip 준비 가능한 경우만).

- [ ] **Step 6: 커밋(있다면)**

Task 1~7에서 이미 각각 커밋했으므로 이 태스크는 검증뿐이며 추가 커밋은 보통 불필요하다. 검증 중 결함을 발견해 수정했다면 별도 커밋(`🐛 fix: ...`)으로 남긴다.
