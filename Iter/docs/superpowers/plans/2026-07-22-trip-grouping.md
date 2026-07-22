# 여행 그룹핑(Trip Grouping) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 연속된 날짜를 하나의 "여행"으로 묶어 커버 사진·기간·사진 수를 보여주는 "내 여행" 화면과, 여행 단위 SNS 공유를 Iter(varius 저장소, CodeIgniter 4)에 추가한다.

**Architecture:** `trips` 테이블(범위 기반, 멤버십은 `[start_date, end_date]`로 계산)에 사용자가 직접 확정한 여행만 저장하고, `TripSuggestionService`가 아직 저장되지 않은 날짜들을 3일 공백 규칙으로 묶어 매번 즉석 계산해 "제안"으로 보여준다. `TripSummaryService`가 날짜별 사진 집계(개수·썸네일 후보)를 계산해 상세 페이지·공개 공유 페이지 양쪽에서 재사용한다. 여행 단위 공유는 기존 `share_links`(날짜 단위) 패턴을 그대로 복제한 `trip_share_links`로 구현한다.

**Tech Stack:** PHP 8.4+/CodeIgniter 4, MySQL(운영)/SQLite3 in-memory(`tests` DB 그룹, `app/Config/Database.php`), PHPUnit 10.5, PHPStan(레벨 6), PHP-CS-Fixer(PSR-12), 프레임워크리스 프론트(각 뷰에 인라인 vanilla JS).

## Global Constraints

- `declare(strict_types=1)` 모든 PHP 파일 필수.
- 제목 ≤100자, 설명(body) ≤2000자 — `day_notes`/`time_notes`와 동일 상한.
- 여행 기간 상한 60일(겹침 스캔·공개 페이지 렌더링 방어).
- 겹침 판정 공식: 기존 여행 `[e.start, e.end]`와 신규/수정 대상 `[n.start, n.end]`이 `e.start <= n.end AND e.end >= n.start`를 만족하면 겹침(수정 시 자기 자신 제외).
- 모든 조회·수정·삭제는 소유자 검사 실패 시 404(403 아님 — 존재 노출 방지, 기존 컨벤션).
- 날짜는 항상 KST 기준으로 표시·그룹핑하고 저장은 UTC(`App\Support\TimeConverter` 재사용).
- 쓰기 엔드포인트는 `sessionRateLimit:trips,120` 필터.
- `composer ci`(CS Fixer → PHPStan → PHPUnit) 그린 없이 다음 태스크로 넘어가지 않는다. `composer check`는 CS Fixer를 빠뜨리므로 사용 금지.
- 모든 신규 기능은 TDD(RED → GREEN)로 구현한다.

---

## Task 1: `trips` 마이그레이션 + `TripModel`

**Files:**
- Create: `app/Database/Migrations/2026-07-22-140000_CreateTrips.php`
- Create: `app/Models/TripModel.php`
- Create: `tests/database/TripModelTest.php`

**Interfaces:**
- Consumes: `App\Models\UserModel::upsertByGoogleSub(string $googleSub, ?string $email, ?string $name): int`(기존), `App\Models\PhotoLocationModel::saveMany(int $userId, array $locations): int`(기존, cover FK 테스트용), `App\Services\Ingest\PhotoLocation` 생성자(기존).
- Produces: `App\Models\TripModel` — `findByUserOrdered(int $userId): list<array<string,mixed>>`, `findOwned(int $id, int $userId): ?array<string,mixed>`, `overlaps(int $userId, string $startDate, string $endDate, ?int $excludeId = null): bool`, 그리고 base `Model`의 `insert()`/`update()`/`delete()`/`find()`를 그대로 사용(`$allowedFields`: `user_id, title, body, start_date, end_date, cover_photo_id`).

- [ ] **Step 1: 마이그레이션 작성**

```php
<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 여행 그룹핑 — 연속된 날짜를 하나의 여행으로 묶는다. 멤버십은 별도 테이블 없이
 * [start_date, end_date] 범위로 계산한다(그 범위 안에 사진이 있는 날짜들).
 */
class CreateTrips extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'user_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'title' => ['type' => 'VARCHAR', 'constraint' => 100, 'default' => ''],
            'body' => ['type' => 'TEXT', 'null' => true],
            'start_date' => ['type' => 'DATE'],
            'end_date' => ['type' => 'DATE'],
            'cover_photo_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['user_id', 'start_date'], false, false, 'idx_trips_user_start');
        $this->forge->addForeignKey('user_id', 'users', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('cover_photo_id', 'photo_locations', 'id', '', 'SET NULL');
        $this->forge->createTable('trips');
    }

    public function down(): void
    {
        $this->forge->dropTable('trips');
    }
}
```

`cover_photo_id`를 `SET NULL` FK로 걸어, 커버로 쓰이던 사진이 삭제되면(`PhotoController::delete`) DB 레벨에서 자동으로 비워지게 한다 — 애플리케이션 코드에서 별도 정리 로직이 필요 없다.

- [ ] **Step 2: `TripModelTest` 작성(RED)**

`tests/database/TripModelTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\PhotoLocationModel;
use App\Models\TripModel;
use App\Models\UserModel;
use App\Services\Ingest\PhotoLocation;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class TripModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $refresh = true;
    protected $namespace = 'App';

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (new UserModel())->upsertByGoogleSub('sub-trip', 'trip@example.com', 'Trip');
    }

    public function testInsertAndFindByUserOrdered(): void
    {
        $model = new TripModel();
        $model->insert([
            'user_id' => $this->userId, 'title' => '서울 여행', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-17', 'cover_photo_id' => null,
        ]);
        $model->insert([
            'user_id' => $this->userId, 'title' => '부산 여행', 'body' => '',
            'start_date' => '2024-05-01', 'end_date' => '2024-05-02', 'cover_photo_id' => null,
        ]);

        $trips = $model->findByUserOrdered($this->userId);

        // 최신 시작일순.
        $this->assertCount(2, $trips);
        $this->assertSame('부산 여행', $trips[0]['title']);
        $this->assertSame('서울 여행', $trips[1]['title']);
    }

    public function testFindOwnedReturnsNullForOtherUsersTrip(): void
    {
        $otherId = (new UserModel())->upsertByGoogleSub('sub-trip-2', 'trip2@example.com', 'Trip2');
        $model = new TripModel();
        $id = $model->insert([
            'user_id' => $otherId, 'title' => 'T', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-15', 'cover_photo_id' => null,
        ]);

        $this->assertNull($model->findOwned((int) $id, $this->userId));
        $this->assertNotNull($model->findOwned((int) $id, $otherId));
    }

    public function testOverlapsDetectsIntersectingRange(): void
    {
        $model = new TripModel();
        $model->insert([
            'user_id' => $this->userId, 'title' => 'T', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-17', 'cover_photo_id' => null,
        ]);

        // 부분 겹침(16일~20일)과 완전 포함(14일~18일) 모두 겹침으로 판정.
        $this->assertTrue($model->overlaps($this->userId, '2024-03-16', '2024-03-20'));
        $this->assertTrue($model->overlaps($this->userId, '2024-03-14', '2024-03-18'));
        // 바로 다음날부터 시작하면 겹치지 않음.
        $this->assertFalse($model->overlaps($this->userId, '2024-03-18', '2024-03-20'));
    }

    public function testOverlapsExcludesGivenIdForUpdateScenario(): void
    {
        $model = new TripModel();
        $id = $model->insert([
            'user_id' => $this->userId, 'title' => 'T', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-17', 'cover_photo_id' => null,
        ]);

        $this->assertTrue($model->overlaps($this->userId, '2024-03-15', '2024-03-17'));
        $this->assertFalse($model->overlaps($this->userId, '2024-03-15', '2024-03-17', (int) $id));
    }

    public function testOverlapsIsScopedToUser(): void
    {
        $otherId = (new UserModel())->upsertByGoogleSub('sub-trip-3', 'trip3@example.com', 'Trip3');
        $model = new TripModel();
        $model->insert([
            'user_id' => $otherId, 'title' => 'T', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-17', 'cover_photo_id' => null,
        ]);

        $this->assertFalse($model->overlaps($this->userId, '2024-03-15', '2024-03-17'));
    }

    public function testDeletingCoverPhotoNullsCoverPhotoIdViaForeignKey(): void
    {
        $photoModel = new PhotoLocationModel();
        $photoModel->saveMany($this->userId, [new PhotoLocation('p1', 37.5, 127.0, '2024-03-15 09:00:00')]);
        $photoId = (int) $photoModel->where('source_item_id', 'p1')->first()['id'];

        $model = new TripModel();
        $tripId = $model->insert([
            'user_id' => $this->userId, 'title' => 'T', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-15', 'cover_photo_id' => $photoId,
        ]);

        $photoModel->delete($photoId);

        $trip = $model->find((int) $tripId);
        $this->assertNull($trip['cover_photo_id']);
    }

    public function testDeleteRemovesTrip(): void
    {
        $model = new TripModel();
        $id = $model->insert([
            'user_id' => $this->userId, 'title' => 'T', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-15', 'cover_photo_id' => null,
        ]);

        $model->delete((int) $id);

        $this->assertNull($model->findOwned((int) $id, $this->userId));
    }
}
```

- [ ] **Step 3: RED 확인**

Run: `vendor/bin/phpunit --no-coverage tests/database/TripModelTest.php`
Expected: ERRORS — `Class "App\Models\TripModel" not found`(파일이 아직 없음).

- [ ] **Step 4: `TripModel` 구현**

`app/Models/TripModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class TripModel extends Model
{
    protected $table = 'trips';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;

    /**
     * @var list<string>
     */
    protected $allowedFields = [
        'user_id',
        'title',
        'body',
        'start_date',
        'end_date',
        'cover_photo_id',
    ];

    /**
     * 사용자의 여행을 최신 시작일순으로 조회한다.
     *
     * @return list<array<string, mixed>>
     */
    public function findByUserOrdered(int $userId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->where('user_id', $userId)
            ->orderBy('start_date', 'DESC')
            ->findAll();

        return $rows;
    }

    /**
     * 여행이 이 사용자 소유일 때만 반환한다(다른 사용자 접근 방지).
     *
     * @return array<string, mixed>|null
     */
    public function findOwned(int $id, int $userId): ?array
    {
        /** @var array<string, mixed>|null $row */
        $row = $this->where('id', $id)
            ->where('user_id', $userId)
            ->first();

        return $row;
    }

    /**
     * 주어진 기간이 이 사용자의 기존 여행과 겹치는지 확인한다(표준 구간 겹침 공식).
     *
     * @param int|null $excludeId 수정 시 자기 자신을 제외하기 위한 여행 id
     */
    public function overlaps(int $userId, string $startDate, string $endDate, ?int $excludeId = null): bool
    {
        $builder = $this->where('user_id', $userId)
            ->where('start_date <=', $endDate)
            ->where('end_date >=', $startDate);

        if ($excludeId !== null) {
            $builder->where('id !=', $excludeId);
        }

        return $builder->countAllResults() > 0;
    }
}
```

- [ ] **Step 5: GREEN 확인**

Run: `vendor/bin/phpunit --no-coverage tests/database/TripModelTest.php`
Expected: `OK (7 tests, ...)`

- [ ] **Step 6: 커밋**

```bash
git add app/Database/Migrations/2026-07-22-140000_CreateTrips.php app/Models/TripModel.php tests/database/TripModelTest.php
git commit -m "✨ feat: trips 테이블·모델 추가(여행 그룹핑 기반)"
```

---

## Task 2: `trip_share_links` 마이그레이션 + `TripShareLinkModel`

**Files:**
- Create: `app/Database/Migrations/2026-07-22-141000_CreateTripShareLinks.php`
- Create: `app/Models/TripShareLinkModel.php`
- Create: `tests/database/TripShareLinkModelTest.php`

**Interfaces:**
- Consumes: `App\Models\TripModel::insert()`/`delete()`(Task 1), `App\Models\UserModel::upsertByGoogleSub()`.
- Produces: `App\Models\TripShareLinkModel` — `createOrGet(int $tripId): string`, `findByToken(string $token): ?int`.

- [ ] **Step 1: 마이그레이션 작성**

```php
<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 여행 단위 SNS 공유 링크 — 무작위 토큰으로 비로그인 열람을 허용한다.
 * 기존 share_links(날짜 단위)와 병렬 구조.
 */
class CreateTripShareLinks extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'trip_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'token' => ['type' => 'CHAR', 'constraint' => 32],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('token', 'uniq_trip_share_links_token');
        $this->forge->addUniqueKey('trip_id', 'uniq_trip_share_links_trip');
        $this->forge->addForeignKey('trip_id', 'trips', 'id', '', 'CASCADE');
        $this->forge->createTable('trip_share_links');
    }

    public function down(): void
    {
        $this->forge->dropTable('trip_share_links');
    }
}
```

- [ ] **Step 2: `TripShareLinkModelTest` 작성(RED)**

`tests/database/TripShareLinkModelTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\TripModel;
use App\Models\TripShareLinkModel;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class TripShareLinkModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $refresh = true;
    protected $namespace = 'App';

    private int $tripId;

    protected function setUp(): void
    {
        parent::setUp();
        $userId = (new UserModel())->upsertByGoogleSub('sub-tripshare', 'tripshare@example.com', 'TripShare');
        $this->tripId = (int) (new TripModel())->insert([
            'user_id' => $userId, 'title' => 'T', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-17', 'cover_photo_id' => null,
        ]);
    }

    public function testCreateOrGetGeneratesUnguessableToken(): void
    {
        $token = (new TripShareLinkModel())->createOrGet($this->tripId);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
    }

    public function testCreateOrGetReturnsSameTokenForSameTrip(): void
    {
        $model = new TripShareLinkModel();
        $first = $model->createOrGet($this->tripId);
        $second = $model->createOrGet($this->tripId);

        $this->assertSame($first, $second);
        $this->assertSame(1, $model->where('trip_id', $this->tripId)->countAllResults());
    }

    public function testFindByTokenReturnsTripId(): void
    {
        $model = new TripShareLinkModel();
        $token = $model->createOrGet($this->tripId);

        $this->assertSame($this->tripId, $model->findByToken($token));
    }

    public function testFindByTokenReturnsNullForUnknownToken(): void
    {
        $this->assertNull((new TripShareLinkModel())->findByToken(str_repeat('0', 32)));
    }

    public function testShareLinkIsRemovedWhenTripDeletedViaForeignKey(): void
    {
        $model = new TripShareLinkModel();
        $token = $model->createOrGet($this->tripId);

        (new TripModel())->delete($this->tripId);

        $this->assertNull($model->findByToken($token));
    }
}
```

- [ ] **Step 3: RED 확인**

Run: `vendor/bin/phpunit --no-coverage tests/database/TripShareLinkModelTest.php`
Expected: ERRORS — `Class "App\Models\TripShareLinkModel" not found`.

- [ ] **Step 4: `TripShareLinkModel` 구현**

`app/Models/TripShareLinkModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class TripShareLinkModel extends Model
{
    protected $table = 'trip_share_links';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = '';

    /**
     * @var list<string>
     */
    protected $allowedFields = [
        'trip_id',
        'token',
    ];

    /**
     * 해당 여행의 공유 링크를 반환한다(있으면 재사용, 없으면 새로 발급).
     *
     * 링크 재발급 방지 — 같은 여행을 다시 공유해도 이미 퍼진 링크가 유지된다.
     */
    public function createOrGet(int $tripId): string
    {
        $existing = $this->where('trip_id', $tripId)->first();
        if ($existing !== null) {
            return (string) $existing['token'];
        }

        $token = bin2hex(random_bytes(16));
        $this->insert([
            'trip_id' => $tripId,
            'token' => $token,
        ]);

        return $token;
    }

    /**
     * 토큰으로 여행 id 를 조회한다.
     */
    public function findByToken(string $token): ?int
    {
        $row = $this->select('trip_id')->where('token', $token)->first();

        return $row === null ? null : (int) $row['trip_id'];
    }
}
```

- [ ] **Step 5: GREEN 확인**

Run: `vendor/bin/phpunit --no-coverage tests/database/TripShareLinkModelTest.php`
Expected: `OK (5 tests, ...)`

- [ ] **Step 6: 커밋**

```bash
git add app/Database/Migrations/2026-07-22-141000_CreateTripShareLinks.php app/Models/TripShareLinkModel.php tests/database/TripShareLinkModelTest.php
git commit -m "✨ feat: trip_share_links 테이블·모델 추가(여행 단위 공유 토큰)"
```

---

## Task 3: `PhotoLocationModel` 확장 — `countBetween`, `firstThumbnailBetween`

**Files:**
- Modify: `app/Models/PhotoLocationModel.php` (`findByUserBetween` 메서드 뒤에 추가, 현재 라인 52-63 부근)
- Modify: `tests/database/PhotoLocationModelTest.php` (파일 끝, 마지막 `}` 앞에 테스트 추가)

**Interfaces:**
- Consumes: 없음(기존 `photo_locations` 스키마만 사용).
- Produces: `PhotoLocationModel::countBetween(int $userId, string $startUtc, string $endUtc): int`, `PhotoLocationModel::firstThumbnailBetween(int $userId, string $startUtc, string $endUtc): ?int`.

- [ ] **Step 1: 테스트 추가(RED)**

`tests/database/PhotoLocationModelTest.php`의 마지막 `}` 바로 앞에 추가:

```php
    public function testCountBetweenCountsOnlyWithinRangeForUser(): void
    {
        $otherUserId = (new UserModel())->upsertByGoogleSub('sub-loc-count', 'count@example.com', 'Count');

        $model = new PhotoLocationModel();
        $model->saveMany($this->userId, [
            new PhotoLocation('c1', 37.5, 127.0, '2024-03-15 09:00:00'),
            new PhotoLocation('c2', 37.6, 127.1, '2024-03-16 09:00:00'),
            new PhotoLocation('c3', 37.6, 127.1, '2024-03-20 09:00:00'), // 범위 밖.
        ]);
        $model->saveMany($otherUserId, [
            new PhotoLocation('c4', 37.5, 127.0, '2024-03-15 10:00:00'),
        ]);

        $count = $model->countBetween($this->userId, '2024-03-15 00:00:00', '2024-03-16 23:59:59');

        $this->assertSame(2, $count);
    }

    public function testFirstThumbnailBetweenReturnsEarliestPhotoWithThumbnail(): void
    {
        $model = new PhotoLocationModel();
        $model->saveMany($this->userId, [
            new PhotoLocation('f1', 37.5, 127.0, '2024-03-15 09:00:00'), // 썸네일 없음.
            new PhotoLocation('f2', 37.6, 127.1, '2024-03-15 10:00:00', '/thumbs/f2.jpg'),
            new PhotoLocation('f3', 37.6, 127.1, '2024-03-15 11:00:00', '/thumbs/f3.jpg'),
        ]);
        $f2Id = (int) $model->where('source_item_id', 'f2')->first()['id'];

        $id = $model->firstThumbnailBetween($this->userId, '2024-03-15 00:00:00', '2024-03-15 23:59:59');

        $this->assertSame($f2Id, $id);
    }

    public function testFirstThumbnailBetweenReturnsNullWhenNoneHaveThumbnails(): void
    {
        $model = new PhotoLocationModel();
        $model->saveMany($this->userId, [
            new PhotoLocation('n1', 37.5, 127.0, '2024-03-15 09:00:00'),
        ]);

        $this->assertNull($model->firstThumbnailBetween($this->userId, '2024-03-15 00:00:00', '2024-03-15 23:59:59'));
    }
```

- [ ] **Step 2: RED 확인**

Run: `vendor/bin/phpunit --no-coverage tests/database/PhotoLocationModelTest.php`
Expected: ERRORS — `Call to undefined method App\Models\PhotoLocationModel::countBetween()`.

- [ ] **Step 3: 구현 추가**

`app/Models/PhotoLocationModel.php`의 `findByUserBetween` 메서드(현재 52-63행) 바로 뒤에 삽입:

```php
    /**
     * 촬영 시각(UTC) 범위 내 사용자 좌표 개수를 센다(여행 카드 사진 수 표시용).
     */
    public function countBetween(int $userId, string $startUtc, string $endUtc): int
    {
        return $this->where('user_id', $userId)
            ->where('taken_at >=', $startUtc)
            ->where('taken_at <=', $endUtc)
            ->countAllResults();
    }

    /**
     * 촬영 시각(UTC) 범위 내에서 썸네일이 있는 가장 이른 사진의 id 를 반환한다
     * (여행 커버 사진 자동 선택용). 썸네일이 있는 사진이 없으면 null.
     */
    public function firstThumbnailBetween(int $userId, string $startUtc, string $endUtc): ?int
    {
        $row = $this->select('id')
            ->where('user_id', $userId)
            ->where('taken_at >=', $startUtc)
            ->where('taken_at <=', $endUtc)
            ->where('thumbnail_path IS NOT NULL')
            ->where('thumbnail_path !=', '')
            ->orderBy('taken_at', 'ASC')
            ->first();

        return $row === null ? null : (int) $row['id'];
    }
```

- [ ] **Step 4: GREEN 확인**

Run: `vendor/bin/phpunit --no-coverage tests/database/PhotoLocationModelTest.php`
Expected: `OK (13 tests, ...)`(기존 10건 + 신규 3건)

- [ ] **Step 5: 커밋**

```bash
git add app/Models/PhotoLocationModel.php tests/database/PhotoLocationModelTest.php
git commit -m "✨ feat: PhotoLocationModel 에 countBetween·firstThumbnailBetween 추가"
```

---

## Task 4: `TripSummaryService` + `Services.php` 등록

**Files:**
- Create: `app/Services/TripSummaryService.php`
- Create: `tests/unit/TripSummaryServiceTest.php`
- Modify: `app/Config/Services.php`

**Interfaces:**
- Consumes: `App\Models\PhotoLocationModel::findByUserBetween()`(기존), `::firstThumbnailBetween()`(Task 3), `App\Support\TimeConverter::kstDateToUtcRange()`/`::utcToKst()`(기존).
- Produces: `App\Services\TripSummaryService::buildDaySummaries(int $userId, string $startDate, string $endDate): list<array{date:string,photo_count:int,thumbnail_ids:list<int>}>`, `::resolveCoverId(?int $storedCoverId, int $userId, string $startDate, string $endDate): ?int`. `service('tripSummary')`로 접근 가능.

- [ ] **Step 1: 테스트 작성(RED)**

`tests/unit/TripSummaryServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PhotoLocationModel;
use App\Services\TripSummaryService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TripSummaryServiceTest extends CIUnitTestCase
{
    /**
     * @param list<array<string, mixed>> $photoRows
     */
    private function service(array $photoRows): TripSummaryService
    {
        $model = $this->createMock(PhotoLocationModel::class);
        $model->method('findByUserBetween')->willReturn($photoRows);

        return new TripSummaryService($model);
    }

    public function testGroupsPhotosByKstDateWithThumbnailIds(): void
    {
        $service = $this->service([
            ['id' => 1, 'lat' => '37.5', 'lng' => '127.0', 'thumbnail_path' => '/t/1.jpg', 'taken_at' => '2024-03-15 01:00:00'], // KST 3/15 10:00
            ['id' => 2, 'lat' => '37.5', 'lng' => '127.0', 'thumbnail_path' => null, 'taken_at' => '2024-03-15 02:00:00'],       // KST 3/15 11:00, 썸네일 없음
            ['id' => 3, 'lat' => '37.5', 'lng' => '127.0', 'thumbnail_path' => '/t/3.jpg', 'taken_at' => '2024-03-15 23:30:00'], // KST 3/16 08:30
        ]);

        $days = $service->buildDaySummaries(1, '2024-03-15', '2024-03-16');

        $this->assertCount(2, $days);
        $this->assertSame('2024-03-15', $days[0]['date']);
        $this->assertSame(2, $days[0]['photo_count']);
        $this->assertSame([1], $days[0]['thumbnail_ids']); // 썸네일 없는 사진은 제외.
        $this->assertSame('2024-03-16', $days[1]['date']);
        $this->assertSame(1, $days[1]['photo_count']);
        $this->assertSame([3], $days[1]['thumbnail_ids']);
    }

    public function testCapsThumbnailIdsAtSixPerDay(): void
    {
        $rows = [];
        for ($i = 1; $i <= 8; $i++) {
            $rows[] = ['id' => $i, 'lat' => '37.5', 'lng' => '127.0', 'thumbnail_path' => '/t/' . $i . '.jpg', 'taken_at' => sprintf('2024-03-15 0%d:00:00', $i)];
        }

        $days = $this->service($rows)->buildDaySummaries(1, '2024-03-15', '2024-03-15');

        $this->assertCount(1, $days);
        $this->assertCount(6, $days[0]['thumbnail_ids']);
        $this->assertSame(8, $days[0]['photo_count']); // 개수는 상한 없이 전부 센다.
    }

    public function testEmptyRangeReturnsEmptyList(): void
    {
        $this->assertSame([], $this->service([])->buildDaySummaries(1, '2024-03-15', '2024-03-17'));
    }

    public function testResolveCoverIdTrustsStoredCoverWhenPresent(): void
    {
        $service = $this->service([]);

        $this->assertSame(42, $service->resolveCoverId(42, 1, '2024-03-15', '2024-03-17'));
    }

    public function testResolveCoverIdFallsBackToFirstThumbnailWhenNoneStored(): void
    {
        $model = $this->createMock(PhotoLocationModel::class);
        $model->method('findByUserBetween')->willReturn([]);
        $model->expects($this->once())
            ->method('firstThumbnailBetween')
            ->with(1, $this->stringContains('2024-03-14'), $this->stringContains('2024-03-17'))
            ->willReturn(9);

        $service = new TripSummaryService($model);

        $this->assertSame(9, $service->resolveCoverId(null, 1, '2024-03-15', '2024-03-17'));
    }
}
```

- [ ] **Step 2: RED 확인**

Run: `vendor/bin/phpunit --no-coverage tests/unit/TripSummaryServiceTest.php`
Expected: ERRORS — `Class "App\Services\TripSummaryService" not found`.

- [ ] **Step 3: `TripSummaryService` 구현**

`app/Services/TripSummaryService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PhotoLocationModel;
use App\Support\TimeConverter;

/**
 * 여행(날짜 범위)의 날짜별 사진 요약 — 여행 상세·공개 공유 페이지가 공용으로 사용한다.
 *
 * 사진 id 만 반환하고 썸네일 URL 프리픽스는 호출측이 붙인다 — 로그인 전용 URL
 * (/thumbnails/{id})과 공개 URL(/t/{token}/thumbnails/{id}) 양쪽에서 재사용하기 위함.
 */
final class TripSummaryService
{
    /** 날짜당 노출할 최대 썸네일 후보 수(공개 페이지 그리드·커버 선택지 과다 방지). */
    private const MAX_THUMBNAILS_PER_DAY = 6;

    public function __construct(
        private readonly PhotoLocationModel $photos,
    ) {
    }

    /**
     * 기간(KST) 내 사진을 날짜별로 묶는다.
     *
     * @return list<array{date: string, photo_count: int, thumbnail_ids: list<int>}>
     */
    public function buildDaySummaries(int $userId, string $startDate, string $endDate): array
    {
        [$startUtc] = TimeConverter::kstDateToUtcRange($startDate);
        [, $endUtc] = TimeConverter::kstDateToUtcRange($endDate);
        $rows = $this->photos->findByUserBetween($userId, $startUtc, $endUtc);

        $grouped = [];
        foreach ($rows as $row) {
            $takenAt = (string) ($row['taken_at'] ?? '');
            if ($takenAt === '') {
                continue;
            }

            $date = substr(TimeConverter::utcToKst($takenAt), 0, 10);
            if (! isset($grouped[$date])) {
                $grouped[$date] = ['photo_count' => 0, 'thumbnail_ids' => []];
            }

            $grouped[$date]['photo_count']++;

            $path = (string) ($row['thumbnail_path'] ?? '');
            if ($path !== '' && count($grouped[$date]['thumbnail_ids']) < self::MAX_THUMBNAILS_PER_DAY) {
                $grouped[$date]['thumbnail_ids'][] = (int) ($row['id'] ?? 0);
            }
        }

        ksort($grouped);

        $days = [];
        foreach ($grouped as $date => $summary) {
            $days[] = [
                'date' => $date,
                'photo_count' => $summary['photo_count'],
                'thumbnail_ids' => $summary['thumbnail_ids'],
            ];
        }

        return $days;
    }

    /**
     * 여행 커버 사진 id 를 정한다. 저장된 값이 있으면 그대로 신뢰하고(사진이 삭제되면
     * FK ON DELETE SET NULL 로 자동으로 비워지므로 별도 소유권 재검증이 필요 없다),
     * 없으면 기간 내 가장 이른 사진(썸네일 있는 것)으로 대체한다.
     */
    public function resolveCoverId(?int $storedCoverId, int $userId, string $startDate, string $endDate): ?int
    {
        if ($storedCoverId !== null) {
            return $storedCoverId;
        }

        [$startUtc] = TimeConverter::kstDateToUtcRange($startDate);
        [, $endUtc] = TimeConverter::kstDateToUtcRange($endDate);

        return $this->photos->firstThumbnailBetween($userId, $startUtc, $endUtc);
    }
}
```

- [ ] **Step 4: GREEN 확인**

Run: `vendor/bin/phpunit --no-coverage tests/unit/TripSummaryServiceTest.php`
Expected: `OK (5 tests, ...)`

- [ ] **Step 5: `Services.php` 등록**

`app/Config/Services.php` 상단 `use` 블록에서 `use App\Services\RouteVisualizationService;` 줄 앞에 추가:

```php
use App\Services\TripSummaryService;
```

(알파벳 순서상 `RouteVisualizationService` 앞, `App\Services\Poi\PoiLookupInterface;` 뒤에 위치)

`routeVisualization()` 메서드 앞에 새 메서드 추가:

```php
    /**
     * 여행(날짜 범위) 날짜별 사진 요약 서비스 — 상세·공개 공유 페이지가 공용으로 사용한다.
     */
    public static function tripSummary(bool $getShared = true): TripSummaryService
    {
        if ($getShared) {
            return static::getSharedInstance('tripSummary');
        }

        return new TripSummaryService(new PhotoLocationModel());
    }

```

- [ ] **Step 6: 전체 유닛 테스트 재확인 + 커밋**

Run: `vendor/bin/phpunit --no-coverage tests/unit/`
Expected: 기존 테스트 모두 여전히 `OK`.

```bash
git add app/Services/TripSummaryService.php app/Config/Services.php tests/unit/TripSummaryServiceTest.php
git commit -m "✨ feat: TripSummaryService 추가(여행 날짜별 사진 요약·커버 결정)"
```

---

## Task 5: `TripSuggestionService` + `Services.php` 등록

**Files:**
- Create: `app/Services/TripSuggestionService.php`
- Create: `tests/unit/TripSuggestionServiceTest.php`
- Modify: `app/Config/Services.php`

**Interfaces:**
- Consumes: `App\Models\PhotoLocationModel::findByUserOrdered(int $userId): list<array<string,mixed>>`(기존), `App\Models\TripModel::findByUserOrdered(int $userId): list<array<string,mixed>>`(Task 1), `App\Support\TimeConverter::utcToKst()`(기존).
- Produces: `App\Services\TripSuggestionService::suggest(int $userId): list<array{start_date:string,end_date:string,photo_count:int,suggested_title:string,first_photo_id:int|null}>`. `service('tripSuggestion')`로 접근 가능.

- [ ] **Step 1: 테스트 작성(RED)**

`tests/unit/TripSuggestionServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PhotoLocationModel;
use App\Models\TripModel;
use App\Services\TripSuggestionService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TripSuggestionServiceTest extends CIUnitTestCase
{
    /**
     * @param list<array<string, mixed>> $photoRows
     * @param list<array<string, mixed>> $existingTrips
     */
    private function service(array $photoRows, array $existingTrips = []): TripSuggestionService
    {
        $photoModel = $this->createMock(PhotoLocationModel::class);
        $photoModel->method('findByUserOrdered')->willReturn($photoRows);

        $tripModel = $this->createMock(TripModel::class);
        $tripModel->method('findByUserOrdered')->willReturn($existingTrips);

        return new TripSuggestionService($photoModel, $tripModel);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id, string $takenAtUtc, ?string $thumbnailPath = '/t.jpg'): array
    {
        return ['id' => $id, 'source_item_id' => 'm' . $id, 'lat' => '37.5', 'lng' => '127.0', 'thumbnail_path' => $thumbnailPath, 'taken_at' => $takenAtUtc];
    }

    public function testGroupsConsecutiveDatesIntoOneSuggestion(): void
    {
        $service = $this->service([
            $this->row(1, '2024-03-15 00:00:00'),
            $this->row(2, '2024-03-16 00:00:00'),
            $this->row(3, '2024-03-17 00:00:00'),
        ]);

        $suggestions = $service->suggest(1);

        $this->assertCount(1, $suggestions);
        $this->assertSame('2024-03-15', $suggestions[0]['start_date']);
        $this->assertSame('2024-03-17', $suggestions[0]['end_date']);
        $this->assertSame(3, $suggestions[0]['photo_count']);
        $this->assertSame('3월 15일~17일 여행', $suggestions[0]['suggested_title']);
        $this->assertSame(1, $suggestions[0]['first_photo_id']);
    }

    public function testSplitsWhenGapIsThreeDaysOrMore(): void
    {
        $service = $this->service([
            $this->row(1, '2024-03-15 00:00:00'),
            $this->row(2, '2024-03-18 00:00:00'), // 3일 공백 → 새 그룹.
        ]);

        $suggestions = $service->suggest(1);

        $this->assertCount(2, $suggestions);
        $this->assertSame('2024-03-15', $suggestions[0]['end_date']);
        $this->assertSame('2024-03-18', $suggestions[1]['start_date']);
    }

    public function testKeepsTogetherWhenGapIsUnderThreeDays(): void
    {
        $service = $this->service([
            $this->row(1, '2024-03-15 00:00:00'),
            $this->row(2, '2024-03-17 00:00:00'), // 2일 공백 → 같은 그룹.
        ]);

        $suggestions = $service->suggest(1);

        $this->assertCount(1, $suggestions);
        $this->assertSame('2024-03-15', $suggestions[0]['start_date']);
        $this->assertSame('2024-03-17', $suggestions[0]['end_date']);
    }

    public function testSingleDayGroupUsesSingleDayTitle(): void
    {
        $suggestions = $this->service([$this->row(1, '2024-03-15 00:00:00')])->suggest(1);

        $this->assertSame('3월 15일 여행', $suggestions[0]['suggested_title']);
    }

    public function testExcludesDatesAlreadyCoveredByExistingTrip(): void
    {
        $service = $this->service(
            [
                $this->row(1, '2024-03-15 00:00:00'),
                $this->row(2, '2024-03-16 00:00:00'),
                $this->row(3, '2024-03-20 00:00:00'),
            ],
            [['start_date' => '2024-03-15', 'end_date' => '2024-03-16']],
        );

        $suggestions = $service->suggest(1);

        // 3/15~16 은 이미 저장된 여행 범위라 제외되고, 3/20 만 새 제안으로 남는다.
        $this->assertCount(1, $suggestions);
        $this->assertSame('2024-03-20', $suggestions[0]['start_date']);
    }

    public function testUtcDateBoundaryShiftsToNextKstDate(): void
    {
        // UTC 23:30 은 KST 로 다음 날 — 날짜 그룹핑이 KST 기준이어야 한다.
        $suggestions = $this->service([$this->row(1, '2024-03-15 23:30:00')])->suggest(1);

        $this->assertSame('2024-03-16', $suggestions[0]['start_date']);
    }

    public function testIgnoresPhotosWithoutThumbnailForFirstPhotoIdButCountsThem(): void
    {
        $suggestions = $this->service([
            $this->row(1, '2024-03-15 00:00:00', null),
            $this->row(2, '2024-03-15 01:00:00', '/t.jpg'),
        ])->suggest(1);

        $this->assertSame(2, $suggestions[0]['photo_count']);
        $this->assertSame(2, $suggestions[0]['first_photo_id']);
    }

    public function testEmptyPhotosReturnsEmptySuggestions(): void
    {
        $this->assertSame([], $this->service([])->suggest(1));
    }
}
```

- [ ] **Step 2: RED 확인**

Run: `vendor/bin/phpunit --no-coverage tests/unit/TripSuggestionServiceTest.php`
Expected: ERRORS — `Class "App\Services\TripSuggestionService" not found`.

- [ ] **Step 3: `TripSuggestionService` 구현**

`app/Services/TripSuggestionService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PhotoLocationModel;
use App\Models\TripModel;
use App\Support\TimeConverter;

/**
 * 여행 자동 제안 — 아직 저장된 여행에 속하지 않은 날짜들을 3일 공백 규칙으로 묶는다.
 *
 * 아무것도 저장하지 않는 순수 계산이다. "내 여행" 목록을 열 때마다 새로 계산되고,
 * 사용자가 저장해야 비로소 TripModel 레코드가 된다.
 */
final class TripSuggestionService
{
    /** 직전 날짜와 이 이상 차이 나면 새 여행으로 나눈다. */
    private const GAP_DAYS = 3;

    public function __construct(
        private readonly PhotoLocationModel $photos,
        private readonly TripModel $trips,
    ) {
    }

    /**
     * @return list<array{start_date: string, end_date: string, photo_count: int, suggested_title: string, first_photo_id: int|null}>
     */
    public function suggest(int $userId): array
    {
        $perDate = $this->groupByKstDate($this->photos->findByUserOrdered($userId));

        $covered = [];
        foreach ($this->trips->findByUserOrdered($userId) as $trip) {
            foreach ($this->datesBetween((string) $trip['start_date'], (string) $trip['end_date']) as $date) {
                $covered[$date] = true;
            }
        }

        $dates = array_values(array_diff(array_keys($perDate), array_keys($covered)));
        sort($dates);

        $suggestions = [];
        foreach ($this->groupByGap($dates) as $group) {
            $startDate = $group[0];
            $endDate = $group[count($group) - 1];

            $photoCount = 0;
            $firstPhotoId = null;
            foreach ($group as $date) {
                $photoCount += $perDate[$date]['count'];
                if ($firstPhotoId === null) {
                    $firstPhotoId = $perDate[$date]['first_photo_id'];
                }
            }

            $suggestions[] = [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'photo_count' => $photoCount,
                'suggested_title' => $this->suggestTitle($startDate, $endDate),
                'first_photo_id' => $firstPhotoId,
            ];
        }

        return $suggestions;
    }

    /**
     * @param list<array<string, mixed>> $rows taken_at(UTC) 오름차순 좌표 행
     *
     * @return array<string, array{count: int, first_photo_id: int|null}>
     */
    private function groupByKstDate(array $rows): array
    {
        $perDate = [];
        foreach ($rows as $row) {
            $takenAt = (string) ($row['taken_at'] ?? '');
            if ($takenAt === '') {
                continue;
            }

            $date = substr(TimeConverter::utcToKst($takenAt), 0, 10);
            if (! isset($perDate[$date])) {
                $perDate[$date] = ['count' => 0, 'first_photo_id' => null];
            }

            $perDate[$date]['count']++;

            $path = (string) ($row['thumbnail_path'] ?? '');
            if ($perDate[$date]['first_photo_id'] === null && $path !== '') {
                $perDate[$date]['first_photo_id'] = (int) ($row['id'] ?? 0);
            }
        }

        return $perDate;
    }

    /**
     * @param list<string> $dates 오름차순 정렬된 YYYY-MM-DD 목록
     *
     * @return list<list<string>>
     */
    private function groupByGap(array $dates): array
    {
        $groups = [];
        $current = [];
        $previous = null;

        foreach ($dates as $date) {
            if ($previous !== null && $this->daysBetween($previous, $date) >= self::GAP_DAYS) {
                $groups[] = $current;
                $current = [];
            }
            $current[] = $date;
            $previous = $date;
        }

        if ($current !== []) {
            $groups[] = $current;
        }

        return $groups;
    }

    private function daysBetween(string $earlier, string $later): int
    {
        return (int) round((strtotime($later) - strtotime($earlier)) / 86400);
    }

    /**
     * @return list<string>
     */
    private function datesBetween(string $start, string $end): array
    {
        $dates = [];
        $cursor = strtotime($start);
        $endTs = strtotime($end);

        while ($cursor <= $endTs) {
            $dates[] = date('Y-m-d', $cursor);
            $cursor = strtotime('+1 day', $cursor);
        }

        return $dates;
    }

    private function suggestTitle(string $startDate, string $endDate): string
    {
        $startLabel = $this->formatMonthDay($startDate);
        if ($startDate === $endDate) {
            return $startLabel . ' 여행';
        }

        $startParts = explode('-', $startDate);
        $endParts = explode('-', $endDate);
        $endLabel = $startParts[1] === $endParts[1]
            ? ((int) $endParts[2]) . '일'
            : $this->formatMonthDay($endDate);

        return $startLabel . '~' . $endLabel . ' 여행';
    }

    private function formatMonthDay(string $date): string
    {
        $parts = explode('-', $date);

        return ((int) $parts[1]) . '월 ' . ((int) $parts[2]) . '일';
    }
}
```

- [ ] **Step 4: GREEN 확인**

Run: `vendor/bin/phpunit --no-coverage tests/unit/TripSuggestionServiceTest.php`
Expected: `OK (8 tests, ...)`

- [ ] **Step 5: `Services.php` 등록**

`use` 블록에 추가(`use App\Models\TripModel;`는 `use App\Models\UserModel;` 앞, `use App\Services\TripSuggestionService;`는 `use App\Services\TripSummaryService;` 앞에):

```php
use App\Models\TripModel;
```

```php
use App\Services\TripSuggestionService;
```

`tripSummary()` 메서드 뒤에 새 메서드 추가:

```php
    /**
     * 여행 자동 제안 서비스 — 아직 저장된 여행에 속하지 않은 날짜를 3일 공백 규칙으로 묶는다.
     */
    public static function tripSuggestion(bool $getShared = true): TripSuggestionService
    {
        if ($getShared) {
            return static::getSharedInstance('tripSuggestion');
        }

        return new TripSuggestionService(new PhotoLocationModel(), new TripModel());
    }

```

- [ ] **Step 6: 전체 유닛 테스트 재확인 + 커밋**

Run: `vendor/bin/phpunit --no-coverage tests/unit/`
Expected: 기존 테스트 모두 여전히 `OK`.

```bash
git add app/Services/TripSuggestionService.php app/Config/Services.php tests/unit/TripSuggestionServiceTest.php
git commit -m "✨ feat: TripSuggestionService 추가(3일 공백 규칙 여행 자동 제안)"
```

---

## Task 6: `TripController` — 목록·생성 + `trips.php` 뷰 + 라우트

**Files:**
- Create: `app/Controllers/TripController.php`
- Create: `app/Views/trips.php`
- Create: `tests/feature/TripControllerTest.php`
- Modify: `app/Config/Routes.php`(파일 끝, `// 계정·데이터 삭제` 블록 앞)

**Interfaces:**
- Consumes: `App\Models\TripModel`(Task 1), `App\Models\PhotoLocationModel::countBetween/firstThumbnailBetween`(Task 3), `service('tripSummary')`(Task 4), `service('tripSuggestion')`(Task 5), `App\Support\TimeConverter`.
- Produces: `TripController::index()`(GET /trips 뷰 껍데기), `::data()`(GET /trips/data JSON), `::create()`(POST /trips). 이후 Task 7·8 이 같은 클래스에 메서드를 추가한다.

- [ ] **Step 1: 테스트 작성(RED)**

`tests/feature/TripControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PhotoLocationModel;
use App\Models\TripModel;
use App\Models\UserModel;
use App\Services\Ingest\PhotoLocation;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class TripControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $refresh = true;
    protected $namespace = 'App';

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (new UserModel())->upsertByGoogleSub('sub-tripc', 'tripc@example.com', 'TripC');
    }

    // ── GET /trips ────────────────────────────────────────────────────

    public function testIndexRedirectsWhenNotLoggedIn(): void
    {
        $result = $this->get('trips');

        $result->assertRedirect();
    }

    public function testIndexRendersPageWithNavAndDataUrl(): void
    {
        $result = $this->withSession(['user_id' => $this->userId])->get('trips');

        $result->assertStatus(200);
        $body = (string) $result->getBody();
        $this->assertStringContainsString('내 여행', $body);
        $this->assertStringContainsString('data-trips-url', $body);
    }

    // ── GET /trips/data ──────────────────────────────────────────────

    public function testDataRequiresLogin(): void
    {
        $result = $this->get('trips/data');

        $result->assertStatus(401);
    }

    public function testDataReturnsSavedTripsWithPhotoCountAndCover(): void
    {
        $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_') . '.jpg';
        file_put_contents($thumbPath, 'bytes');
        (new PhotoLocationModel())->saveMany($this->userId, [
            new PhotoLocation('d1', 37.5, 127.0, '2024-03-15 02:00:00', $thumbPath),
            new PhotoLocation('d2', 37.5, 127.0, '2024-03-16 02:00:00'),
        ]);
        (new TripModel())->insert([
            'user_id' => $this->userId, 'title' => '서울 여행', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-16', 'cover_photo_id' => null,
        ]);

        try {
            $result = $this->withSession(['user_id' => $this->userId])->get('trips/data');

            $result->assertStatus(200);
            $data = json_decode($result->getJSON() ?? '', true);
            $this->assertCount(1, $data['trips']);
            $this->assertSame('서울 여행', $data['trips'][0]['title']);
            $this->assertSame(2, $data['trips'][0]['photo_count']);
            $this->assertNotNull($data['trips'][0]['cover_thumbnail_url']);
        } finally {
            if (is_file($thumbPath)) {
                unlink($thumbPath);
            }
        }
    }

    public function testDataReturnsSuggestionsExcludingSavedTripDates(): void
    {
        (new PhotoLocationModel())->saveMany($this->userId, [
            new PhotoLocation('s1', 37.5, 127.0, '2024-03-15 02:00:00'), // 저장된 여행에 포함.
            new PhotoLocation('s2', 37.5, 127.0, '2024-03-25 02:00:00'), // 미포함 → 제안 대상.
        ]);
        (new TripModel())->insert([
            'user_id' => $this->userId, 'title' => '서울 여행', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-15', 'cover_photo_id' => null,
        ]);

        $result = $this->withSession(['user_id' => $this->userId])->get('trips/data');

        $data = json_decode($result->getJSON() ?? '', true);
        $this->assertCount(1, $data['suggestions']);
        $this->assertSame('2024-03-25', $data['suggestions'][0]['start_date']);
    }

    // ── POST /trips ──────────────────────────────────────────────────

    public function testCreateRequiresLogin(): void
    {
        $result = $this->post('trips', ['title' => 'T', 'body' => '', 'start_date' => '2024-03-15', 'end_date' => '2024-03-16']);

        $result->assertStatus(401);
    }

    public function testCreateRejectsInvalidDateRange(): void
    {
        $result = $this->withSession(['user_id' => $this->userId])
            ->post('trips', ['title' => 'T', 'body' => '', 'start_date' => '2024-03-17', 'end_date' => '2024-03-15']);

        $result->assertStatus(422);
    }

    public function testCreateRejectsSpanOverSixtyDays(): void
    {
        $result = $this->withSession(['user_id' => $this->userId])
            ->post('trips', ['title' => 'T', 'body' => '', 'start_date' => '2024-01-01', 'end_date' => '2024-03-15']);

        $result->assertStatus(422);
    }

    public function testCreateRejectsOverlappingTrip(): void
    {
        (new TripModel())->insert([
            'user_id' => $this->userId, 'title' => '기존 여행', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-17', 'cover_photo_id' => null,
        ]);

        $result = $this->withSession(['user_id' => $this->userId])
            ->post('trips', ['title' => 'T', 'body' => '', 'start_date' => '2024-03-16', 'end_date' => '2024-03-20']);

        $result->assertStatus(422);
    }

    public function testCreateRejectsCoverPhotoOutsideRange(): void
    {
        (new PhotoLocationModel())->saveMany($this->userId, [
            new PhotoLocation('c1', 37.5, 127.0, '2024-03-25 02:00:00'),
        ]);
        $photoId = (int) (new PhotoLocationModel())->where('source_item_id', 'c1')->first()['id'];

        $result = $this->withSession(['user_id' => $this->userId])
            ->post('trips', [
                'title' => 'T', 'body' => '', 'start_date' => '2024-03-15', 'end_date' => '2024-03-16',
                'cover_photo_id' => (string) $photoId,
            ]);

        $result->assertStatus(422);
    }

    public function testCreatePersistsTrip(): void
    {
        $result = $this->withSession(['user_id' => $this->userId])
            ->post('trips', ['title' => '서울 여행', 'body' => '고궁 투어', 'start_date' => '2024-03-15', 'end_date' => '2024-03-16']);

        $result->assertStatus(200);
        $this->seeInDatabase('trips', ['user_id' => $this->userId, 'title' => '서울 여행']);
    }
}
```

- [ ] **Step 2: RED 확인**

Run: `vendor/bin/phpunit --no-coverage tests/feature/TripControllerTest.php`
Expected: ERRORS — 라우트 없음(`Can't find a route` 등)/`Class "App\Controllers\TripController" not found`.

- [ ] **Step 3: `TripController` 생성**

`app/Controllers/TripController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\PhotoLocationModel;
use App\Models\TripModel;
use App\Support\TimeConverter;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * 여행 그룹핑 — 연속된 날짜를 하나의 여행으로 묶어 커버 사진·기간·사진 수를 보여준다.
 *
 * 여행 경계는 TripSuggestionService 가 자동 제안하고, 사용자가 저장해야 비로소
 * TripModel 레코드가 된다. 멤버십은 별도 테이블 없이 [start_date, end_date] 범위로
 * 계산한다. 컨트롤러는 인증 가드·검증·응답만 담당한다.
 */
class TripController extends BaseController
{
    private const MAX_TITLE_LENGTH = 100;
    private const MAX_BODY_LENGTH = 2000;
    private const MAX_TRIP_DAYS = 60;

    /**
     * "내 여행" 목록 페이지 껍데기(GET /trips).
     */
    public function index(): ResponseInterface|RedirectResponse|string
    {
        if ($this->currentUserId() === null) {
            return redirect()->to('/auth/google');
        }

        helper('url');

        return view('trips', [
            'tripsUrl' => site_url('trips'),
            'uploadUrl' => site_url('upload'),
            'mapUrl' => site_url('map'),
            'logoutUrl' => site_url('auth/logout'),
        ]);
    }

    /**
     * 저장된 여행 + 자동 제안 목록(JSON, GET /trips/data).
     */
    public function data(): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => '로그인이 필요합니다.']);
        }

        $photoModel = model(PhotoLocationModel::class);
        $summaryService = service('tripSummary');

        $trips = [];
        foreach (model(TripModel::class)->findByUserOrdered($userId) as $trip) {
            $startDate = (string) $trip['start_date'];
            $endDate = (string) $trip['end_date'];
            [$startUtc] = TimeConverter::kstDateToUtcRange($startDate);
            [, $endUtc] = TimeConverter::kstDateToUtcRange($endDate);

            $storedCoverId = $trip['cover_photo_id'] !== null ? (int) $trip['cover_photo_id'] : null;
            $coverId = $summaryService->resolveCoverId($storedCoverId, $userId, $startDate, $endDate);

            $trips[] = [
                'id' => (int) $trip['id'],
                'title' => (string) $trip['title'],
                'start_date' => $startDate,
                'end_date' => $endDate,
                'photo_count' => $photoModel->countBetween($userId, $startUtc, $endUtc),
                'cover_thumbnail_url' => $coverId !== null ? '/thumbnails/' . $coverId : null,
            ];
        }

        $suggestions = [];
        foreach (service('tripSuggestion')->suggest($userId) as $s) {
            $suggestions[] = [
                'start_date' => $s['start_date'],
                'end_date' => $s['end_date'],
                'photo_count' => $s['photo_count'],
                'suggested_title' => $s['suggested_title'],
                'first_photo_id' => $s['first_photo_id'],
                'first_thumbnail_url' => $s['first_photo_id'] !== null ? '/thumbnails/' . $s['first_photo_id'] : null,
            ];
        }

        return $this->response->setJSON(['trips' => $trips, 'suggestions' => $suggestions]);
    }

    /**
     * 여행 생성(POST /trips).
     */
    public function create(): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => '로그인이 필요합니다.']);
        }

        $title = trim((string) $this->request->getPost('title'));
        $body = trim((string) $this->request->getPost('body'));
        $startDate = (string) $this->request->getPost('start_date');
        $endDate = (string) $this->request->getPost('end_date');
        $coverRaw = $this->request->getPost('cover_photo_id');

        [$valid, $error] = $this->validateTripFields($title, $body, $startDate, $endDate);
        if (! $valid) {
            return $this->response->setStatusCode(422)->setJSON(['error' => $error]);
        }

        $tripModel = model(TripModel::class);
        if ($tripModel->overlaps($userId, $startDate, $endDate)) {
            return $this->response->setStatusCode(422)->setJSON(['error' => '겹치는 여행이 있습니다.']);
        }

        [$coverValid, $coverId, $coverError] = $this->validateCoverPhotoId(
            $coverRaw === null ? null : (string) $coverRaw,
            $userId,
            $startDate,
            $endDate,
        );
        if (! $coverValid) {
            return $this->response->setStatusCode(422)->setJSON(['error' => $coverError]);
        }

        $id = $tripModel->insert([
            'user_id' => $userId,
            'title' => $title,
            'body' => $body,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'cover_photo_id' => $coverId,
        ]);

        return $this->response->setJSON([
            'id' => (int) $id,
            'title' => $title,
            'body' => $body,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'cover_photo_id' => $coverId,
        ]);
    }

    /**
     * 제목·설명·기간 유효성을 검증한다.
     *
     * @return array{0: bool, 1: string} [유효 여부, 에러 메시지]
     */
    private function validateTripFields(string $title, string $body, string $startDate, string $endDate): array
    {
        if (! $this->isValidDate($startDate) || ! $this->isValidDate($endDate)) {
            return [false, '날짜 형식이 올바르지 않습니다(YYYY-MM-DD).'];
        }
        if ($endDate < $startDate) {
            return [false, '종료일은 시작일 이후여야 합니다.'];
        }
        if ((strtotime($endDate) - strtotime($startDate)) / 86400 + 1 > self::MAX_TRIP_DAYS) {
            return [false, '여행 기간은 최대 ' . self::MAX_TRIP_DAYS . '일까지 설정할 수 있습니다.'];
        }
        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            return [false, '제목은 ' . self::MAX_TITLE_LENGTH . '자 이하여야 합니다.'];
        }
        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            return [false, '설명은 ' . self::MAX_BODY_LENGTH . '자 이하여야 합니다.'];
        }

        return [true, ''];
    }

    /**
     * cover_photo_id 원시 입력을 검증한다.
     *
     * @return array{0: bool, 1: int|null, 2: string} [유효 여부, 사진 id, 에러 메시지]
     */
    private function validateCoverPhotoId(?string $raw, int $userId, string $startDate, string $endDate): array
    {
        if ($raw === null || $raw === '') {
            return [true, null, ''];
        }
        if (! ctype_digit($raw)) {
            return [false, null, '커버 사진 지정이 올바르지 않습니다.'];
        }

        $id = (int) $raw;
        $photo = model(PhotoLocationModel::class)->findOwned($id, $userId);
        if ($photo === null) {
            return [false, null, '커버 사진을 찾을 수 없습니다.'];
        }

        $date = substr(TimeConverter::utcToKst((string) ($photo['taken_at'] ?? '')), 0, 10);
        if ($date < $startDate || $date > $endDate) {
            return [false, null, '커버 사진은 여행 기간 안의 사진이어야 합니다.'];
        }

        return [true, $id, ''];
    }

    /**
     * YYYY-MM-DD 형식이면서 실제 존재하는 날짜인지 검증한다.
     */
    private function isValidDate(string $date): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
```

- [ ] **Step 4: `trips.php` 뷰 생성**

`app/Views/trips.php`:

```php
<?php

declare(strict_types=1);

/**
 * "내 여행" 목록 — 저장된 여행 + 자동 제안 카드.
 *
 * @var string $tripsUrl
 * @var string $uploadUrl
 * @var string $mapUrl
 * @var string $logoutUrl
 */
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>내 여행 — Iter</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png">
    <link rel="stylesheet" href="/assets/nav.css">
    <style>
        html, body { margin: 0; font-family: system-ui, sans-serif; color: #222; }
        main { padding: 40px 20px; max-width: 960px; margin: 0 auto; }
        .page-title { font-size: 22px; margin: 0 0 6px; }
        .page-lead { margin: 0 0 24px; font-size: 14px; color: #444; line-height: 1.6; }
        h2.section-title { font-size: 16px; margin: 28px 0 12px; }
        .trip-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px; }
        .trip-card {
            display: block; text-decoration: none; color: inherit;
            background: #fff; border: 1px solid #eee; border-radius: 12px; overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }
        .trip-card-cover { width: 100%; height: 130px; object-fit: cover; background: #f0f0f0; display: block; }
        .trip-card-body { padding: 12px 14px; }
        .trip-card-title { font-size: 14px; font-weight: 600; margin: 0 0 4px; }
        .trip-card-meta { font-size: 12px; color: #777; }
        .suggestion-card { background: #f7f9ff; border: 1px dashed #b9cdfa; }
        .suggestion-form { padding: 10px 14px 14px; display: none; }
        .suggestion-form.open { display: block; }
        .suggestion-form input { width: 100%; box-sizing: border-box; border: 1px solid #ccd6f0; border-radius: 6px; font: inherit; padding: 6px 9px; margin-bottom: 6px; }
        .btn {
            display: inline-block; padding: 6px 14px; border-radius: 8px; border: 1.5px solid transparent;
            background: #1a73e8; color: #fff; font-size: 13px; font-weight: 600; cursor: pointer;
        }
        .btn:disabled { background: #9db8e8; cursor: not-allowed; }
        .empty { color: #777; font-size: 13px; padding: 12px 0; }
    </style>
</head>
<body>
    <?= view('partials/nav', ['uploadUrl' => $uploadUrl, 'mapUrl' => $mapUrl, 'tripsUrl' => $tripsUrl, 'logoutUrl' => $logoutUrl]) ?>
    <main data-trips-url="<?= esc($tripsUrl, 'attr') ?>">
        <h1 class="page-title">내 여행</h1>
        <p class="page-lead">연속된 날짜를 하나의 여행으로 묶어 커버 사진·기간·사진 수를 한눈에 봅니다.</p>

        <h2 class="section-title">저장된 여행</h2>
        <div class="trip-grid" id="saved-trips"></div>
        <div class="empty" id="saved-empty" hidden>아직 저장된 여행이 없습니다.</div>

        <h2 class="section-title">제안된 여행</h2>
        <div class="trip-grid" id="suggested-trips"></div>
        <div class="empty" id="suggested-empty" hidden>새로 제안할 여행이 없습니다.</div>
    </main>

    <script>
        (function () {
            var mainEl = document.querySelector('main');
            var tripsUrl = mainEl.dataset.tripsUrl;
            var savedEl = document.getElementById('saved-trips');
            var savedEmptyEl = document.getElementById('saved-empty');
            var suggestedEl = document.getElementById('suggested-trips');
            var suggestedEmptyEl = document.getElementById('suggested-empty');

            fetch(tripsUrl + '/data', { headers: { Accept: 'application/json' } })
                .then(function (res) { return res.json(); })
                .then(render)
                .catch(function () {
                    savedEmptyEl.textContent = '여행 목록을 불러오지 못했습니다.';
                    savedEmptyEl.hidden = false;
                });

            function render(data) {
                renderSaved(data.trips || []);
                renderSuggestions(data.suggestions || []);
            }

            function renderSaved(trips) {
                savedEl.innerHTML = '';
                savedEmptyEl.hidden = trips.length > 0;

                trips.forEach(function (trip) {
                    var card = document.createElement('a');
                    card.className = 'trip-card';
                    card.href = tripsUrl + '/' + trip.id;

                    if (trip.cover_thumbnail_url) {
                        var img = document.createElement('img');
                        img.className = 'trip-card-cover';
                        img.src = trip.cover_thumbnail_url;
                        img.alt = '';
                        card.appendChild(img);
                    }

                    var body = document.createElement('div');
                    body.className = 'trip-card-body';

                    var title = document.createElement('div');
                    title.className = 'trip-card-title';
                    title.textContent = trip.title;
                    body.appendChild(title);

                    var meta = document.createElement('div');
                    meta.className = 'trip-card-meta';
                    meta.textContent = formatRange(trip.start_date, trip.end_date) + ' · 사진 ' + trip.photo_count + '장';
                    body.appendChild(meta);

                    card.appendChild(body);
                    savedEl.appendChild(card);
                });
            }

            function renderSuggestions(suggestions) {
                suggestedEl.innerHTML = '';
                suggestedEmptyEl.hidden = suggestions.length > 0;

                suggestions.forEach(function (s) {
                    var card = document.createElement('div');
                    card.className = 'trip-card suggestion-card';

                    if (s.first_thumbnail_url) {
                        var img = document.createElement('img');
                        img.className = 'trip-card-cover';
                        img.src = s.first_thumbnail_url;
                        img.alt = '';
                        card.appendChild(img);
                    }

                    var body = document.createElement('div');
                    body.className = 'trip-card-body';

                    var title = document.createElement('div');
                    title.className = 'trip-card-title';
                    title.textContent = s.suggested_title;
                    body.appendChild(title);

                    var meta = document.createElement('div');
                    meta.className = 'trip-card-meta';
                    meta.textContent = formatRange(s.start_date, s.end_date) + ' · 사진 ' + s.photo_count + '장';
                    body.appendChild(meta);

                    var saveBtn = document.createElement('button');
                    saveBtn.type = 'button';
                    saveBtn.className = 'btn';
                    saveBtn.textContent = '저장';
                    body.appendChild(saveBtn);

                    var form = document.createElement('div');
                    form.className = 'suggestion-form';

                    var titleInput = document.createElement('input');
                    titleInput.type = 'text';
                    titleInput.maxLength = 100;
                    titleInput.value = s.suggested_title;
                    form.appendChild(titleInput);

                    var confirmBtn = document.createElement('button');
                    confirmBtn.type = 'button';
                    confirmBtn.className = 'btn';
                    confirmBtn.textContent = '확정';
                    form.appendChild(confirmBtn);

                    body.appendChild(form);
                    card.appendChild(body);
                    suggestedEl.appendChild(card);

                    saveBtn.addEventListener('click', function () {
                        form.classList.toggle('open');
                    });

                    confirmBtn.addEventListener('click', function () {
                        confirmBtn.disabled = true;
                        fetch(tripsUrl, {
                            method: 'POST',
                            headers: { Accept: 'application/json' },
                            body: new URLSearchParams({
                                title: titleInput.value.trim(),
                                body: '',
                                start_date: s.start_date,
                                end_date: s.end_date
                            })
                        })
                            .then(function (res) {
                                if (!res.ok) { throw new Error('save failed'); }
                                return res.json();
                            })
                            .then(function (created) {
                                window.location.href = tripsUrl + '/' + created.id;
                            })
                            .catch(function () {
                                alert('여행 저장에 실패했습니다.');
                                confirmBtn.disabled = false;
                            });
                    });
                });
            }

            function formatRange(start, end) {
                var s = start.split('-');
                var sLabel = Number(s[1]) + '월 ' + Number(s[2]) + '일';
                if (start === end) { return sLabel; }
                var e = end.split('-');
                var eLabel = s[1] === e[1] ? Number(e[2]) + '일' : Number(e[1]) + '월 ' + Number(e[2]) + '일';
                return sLabel + '~' + eLabel;
            }
        })();
    </script>
</body>
</html>
```

- [ ] **Step 5: 라우트 추가**

`app/Config/Routes.php`의 `// 계정·데이터 삭제` 줄 바로 앞에 추가:

```php
// 여행 그룹핑
$routes->get('trips', 'TripController::index');
$routes->get('trips/data', 'TripController::data'); // (:num) 라우트보다 먼저 선언
$routes->post('trips', 'TripController::create', ['filter' => 'sessionRateLimit:trips,120']);

```

- [ ] **Step 6: GREEN 확인**

Run: `vendor/bin/phpunit --no-coverage tests/feature/TripControllerTest.php`
Expected: `OK (10 tests, ...)`

- [ ] **Step 7: 전체 게이트 확인**

Run: `composer ci`
Expected: `[OK] No errors`(PHPStan), `OK (...)`(PHPUnit).

- [ ] **Step 8: 커밋**

```bash
git add app/Controllers/TripController.php app/Views/trips.php app/Config/Routes.php tests/feature/TripControllerTest.php
git commit -m "✨ feat: 여행 목록·생성 API + 내 여행 화면 추가"
```

---

## Task 7: `TripController` — 상세·수정·삭제 + `trip-detail.php` 뷰(편집)

**Files:**
- Modify: `app/Controllers/TripController.php`(Task 6에서 만든 `create()` 메서드 뒤, `validateTripFields()` 앞에 4개 메서드 삽입)
- Create: `app/Views/trip-detail.php`
- Modify: `tests/feature/TripControllerTest.php`(파일 끝 `}` 앞에 테스트 추가)
- Modify: `app/Config/Routes.php`(Task 6에서 추가한 여행 라우트 블록 안에 추가)

**Interfaces:**
- Consumes: Task 6 의 `TripController` 골격, `service('tripSummary')::buildDaySummaries/resolveCoverId`.
- Produces: `TripController::show(int $id)`, `::showData(int $id)`, `::update(int $id)`, `::delete(int $id)`.

- [ ] **Step 1: 테스트 추가(RED)**

`tests/feature/TripControllerTest.php`의 마지막 `}` 바로 앞에 추가:

```php
    // ── GET /trips/{id} ──────────────────────────────────────────────

    public function testShowRedirectsWhenNotLoggedIn(): void
    {
        $result = $this->get('trips/1');

        $result->assertRedirect();
    }

    public function testShowRendersPageShell(): void
    {
        $result = $this->withSession(['user_id' => $this->userId])->get('trips/1');

        $result->assertStatus(200);
        $body = (string) $result->getBody();
        $this->assertStringContainsString('data-trip-id="1"', $body);
    }

    // ── GET /trips/{id}/data ─────────────────────────────────────────

    public function testShowDataRequiresLogin(): void
    {
        $result = $this->get('trips/1/data');

        $result->assertStatus(401);
    }

    public function testShowDataReturns404WhenNotOwned(): void
    {
        $otherId = (new UserModel())->upsertByGoogleSub('sub-tripc-2', 'tripc2@example.com', 'TripC2');
        $tripId = (int) (new TripModel())->insert([
            'user_id' => $otherId, 'title' => 'T', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-16', 'cover_photo_id' => null,
        ]);

        $result = $this->withSession(['user_id' => $this->userId])->get('trips/' . $tripId . '/data');

        $result->assertStatus(404);
    }

    public function testShowDataReturnsTripAndDaySummaries(): void
    {
        (new PhotoLocationModel())->saveMany($this->userId, [
            new PhotoLocation('sd1', 37.5, 127.0, '2024-03-15 02:00:00'),
            new PhotoLocation('sd2', 37.5, 127.0, '2024-03-16 02:00:00'),
        ]);
        $tripId = (int) (new TripModel())->insert([
            'user_id' => $this->userId, 'title' => '서울 여행', 'body' => '고궁 투어',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-16', 'cover_photo_id' => null,
        ]);

        $result = $this->withSession(['user_id' => $this->userId])->get('trips/' . $tripId . '/data');

        $result->assertStatus(200);
        $data = json_decode($result->getJSON() ?? '', true);
        $this->assertSame('서울 여행', $data['trip']['title']);
        $this->assertCount(2, $data['days']);
        $this->assertSame('2024-03-15', $data['days'][0]['date']);
    }

    // ── POST /trips/{id}/update ──────────────────────────────────────

    public function testUpdateRequiresLogin(): void
    {
        $result = $this->post('trips/1/update', ['title' => 'T', 'body' => '', 'start_date' => '2024-03-15', 'end_date' => '2024-03-16']);

        $result->assertStatus(401);
    }

    public function testUpdateReturns404WhenNotOwned(): void
    {
        $otherId = (new UserModel())->upsertByGoogleSub('sub-tripc-3', 'tripc3@example.com', 'TripC3');
        $tripId = (int) (new TripModel())->insert([
            'user_id' => $otherId, 'title' => 'T', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-16', 'cover_photo_id' => null,
        ]);

        $result = $this->withSession(['user_id' => $this->userId])
            ->post('trips/' . $tripId . '/update', ['title' => 'X', 'body' => '', 'start_date' => '2024-03-15', 'end_date' => '2024-03-16']);

        $result->assertStatus(404);
    }

    public function testUpdateAllowsKeepingSameDateRange(): void
    {
        // 자기 자신의 기존 범위와 겹침 검사를 하면 항상 실패하므로, excludeId 로 제외돼야 한다.
        $tripId = (int) (new TripModel())->insert([
            'user_id' => $this->userId, 'title' => '서울 여행', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-16', 'cover_photo_id' => null,
        ]);

        $result = $this->withSession(['user_id' => $this->userId])
            ->post('trips/' . $tripId . '/update', ['title' => '수정된 제목', 'body' => '', 'start_date' => '2024-03-15', 'end_date' => '2024-03-16']);

        $result->assertStatus(200);
        $this->seeInDatabase('trips', ['id' => $tripId, 'title' => '수정된 제목']);
    }

    public function testUpdateRejectsOverlapWithOtherTrip(): void
    {
        (new TripModel())->insert([
            'user_id' => $this->userId, 'title' => '여행 A', 'body' => '',
            'start_date' => '2024-03-01', 'end_date' => '2024-03-03', 'cover_photo_id' => null,
        ]);
        $tripBId = (int) (new TripModel())->insert([
            'user_id' => $this->userId, 'title' => '여행 B', 'body' => '',
            'start_date' => '2024-03-10', 'end_date' => '2024-03-12', 'cover_photo_id' => null,
        ]);

        $result = $this->withSession(['user_id' => $this->userId])
            ->post('trips/' . $tripBId . '/update', ['title' => '여행 B', 'body' => '', 'start_date' => '2024-03-02', 'end_date' => '2024-03-12']);

        $result->assertStatus(422);
    }

    // ── POST /trips/{id}/delete ──────────────────────────────────────

    public function testDeleteRequiresLogin(): void
    {
        $result = $this->post('trips/1/delete');

        $result->assertStatus(401);
    }

    public function testDeleteReturns404WhenNotOwned(): void
    {
        $otherId = (new UserModel())->upsertByGoogleSub('sub-tripc-4', 'tripc4@example.com', 'TripC4');
        $tripId = (int) (new TripModel())->insert([
            'user_id' => $otherId, 'title' => 'T', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-16', 'cover_photo_id' => null,
        ]);

        $result = $this->withSession(['user_id' => $this->userId])->post('trips/' . $tripId . '/delete');

        $result->assertStatus(404);
        $this->seeInDatabase('trips', ['id' => $tripId]);
    }

    public function testDeleteRemovesTripButKeepsPhotosAndNotes(): void
    {
        (new PhotoLocationModel())->saveMany($this->userId, [
            new PhotoLocation('kd1', 37.5, 127.0, '2024-03-15 02:00:00'),
        ]);
        $tripId = (int) (new TripModel())->insert([
            'user_id' => $this->userId, 'title' => '서울 여행', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-16', 'cover_photo_id' => null,
        ]);

        $result = $this->withSession(['user_id' => $this->userId])->post('trips/' . $tripId . '/delete');

        $result->assertStatus(200);
        $this->dontSeeInDatabase('trips', ['id' => $tripId]);
        $this->seeInDatabase('photo_locations', ['source_item_id' => 'kd1']);
    }
```

- [ ] **Step 2: RED 확인**

Run: `vendor/bin/phpunit --no-coverage tests/feature/TripControllerTest.php`
Expected: 새로 추가한 테스트들이 `Can't find a route` 등으로 실패, 기존 Task 6 테스트는 여전히 통과.

- [ ] **Step 3: 라우트 추가**

`app/Config/Routes.php`의 Task 6 에서 만든 여행 라우트 블록(`$routes->post('trips', ...)` 다음 줄)에 추가:

```php
$routes->get('trips/(:num)', 'TripController::show/$1');
$routes->get('trips/(:num)/data', 'TripController::showData/$1');
$routes->post('trips/(:num)/update', 'TripController::update/$1', ['filter' => 'sessionRateLimit:trips,120']);
$routes->post('trips/(:num)/delete', 'TripController::delete/$1', ['filter' => 'sessionRateLimit:trips,120']);
```

- [ ] **Step 4: `TripController`에 메서드 4개 추가**

`app/Controllers/TripController.php`에서 `create()` 메서드가 끝나는 `}` 바로 뒤, `validateTripFields()` 메서드 바로 앞에 삽입:

```php
    /**
     * 여행 상세/편집 페이지 껍데기(GET /trips/{id}).
     */
    public function show(int $id): ResponseInterface|RedirectResponse|string
    {
        if ($this->currentUserId() === null) {
            return redirect()->to('/auth/google');
        }

        helper('url');

        return view('trip-detail', [
            'tripId' => $id,
            'tripsUrl' => site_url('trips'),
            'uploadUrl' => site_url('upload'),
            'mapUrl' => site_url('map'),
            'logoutUrl' => site_url('auth/logout'),
        ]);
    }

    /**
     * 여행 상세 데이터(JSON, GET /trips/{id}/data).
     */
    public function showData(int $id): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => '로그인이 필요합니다.']);
        }

        $trip = model(TripModel::class)->findOwned($id, $userId);
        if ($trip === null) {
            return $this->response->setStatusCode(404);
        }

        $startDate = (string) $trip['start_date'];
        $endDate = (string) $trip['end_date'];
        $summaryService = service('tripSummary');

        $storedCoverId = $trip['cover_photo_id'] !== null ? (int) $trip['cover_photo_id'] : null;
        $coverId = $summaryService->resolveCoverId($storedCoverId, $userId, $startDate, $endDate);

        $days = [];
        foreach ($summaryService->buildDaySummaries($userId, $startDate, $endDate) as $summary) {
            $firstId = $summary['thumbnail_ids'][0] ?? null;
            $days[] = [
                'date' => $summary['date'],
                'photo_count' => $summary['photo_count'],
                'first_photo_id' => $firstId,
                'first_thumbnail_url' => $firstId !== null ? '/thumbnails/' . $firstId : null,
            ];
        }

        return $this->response->setJSON([
            'trip' => [
                'id' => (int) $trip['id'],
                'title' => (string) $trip['title'],
                'body' => (string) ($trip['body'] ?? ''),
                'start_date' => $startDate,
                'end_date' => $endDate,
                'cover_photo_id' => $storedCoverId,
                'cover_thumbnail_url' => $coverId !== null ? '/thumbnails/' . $coverId : null,
            ],
            'days' => $days,
        ]);
    }

    /**
     * 여행 수정(POST /trips/{id}/update).
     */
    public function update(int $id): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => '로그인이 필요합니다.']);
        }

        $tripModel = model(TripModel::class);
        $trip = $tripModel->findOwned($id, $userId);
        if ($trip === null) {
            return $this->response->setStatusCode(404);
        }

        $title = trim((string) $this->request->getPost('title'));
        $body = trim((string) $this->request->getPost('body'));
        $startDate = (string) $this->request->getPost('start_date');
        $endDate = (string) $this->request->getPost('end_date');
        $coverRaw = $this->request->getPost('cover_photo_id');

        [$valid, $error] = $this->validateTripFields($title, $body, $startDate, $endDate);
        if (! $valid) {
            return $this->response->setStatusCode(422)->setJSON(['error' => $error]);
        }

        if ($tripModel->overlaps($userId, $startDate, $endDate, $id)) {
            return $this->response->setStatusCode(422)->setJSON(['error' => '겹치는 여행이 있습니다.']);
        }

        [$coverValid, $coverId, $coverError] = $this->validateCoverPhotoId(
            $coverRaw === null ? null : (string) $coverRaw,
            $userId,
            $startDate,
            $endDate,
        );
        if (! $coverValid) {
            return $this->response->setStatusCode(422)->setJSON(['error' => $coverError]);
        }

        $tripModel->update($id, [
            'title' => $title,
            'body' => $body,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'cover_photo_id' => $coverId,
        ]);

        return $this->response->setJSON([
            'id' => $id,
            'title' => $title,
            'body' => $body,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'cover_photo_id' => $coverId,
        ]);
    }

    /**
     * 여행 삭제 — Trip 레코드만 지운다(사진·노트는 그대로 남는다, POST /trips/{id}/delete).
     */
    public function delete(int $id): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => '로그인이 필요합니다.']);
        }

        $tripModel = model(TripModel::class);
        if ($tripModel->findOwned($id, $userId) === null) {
            return $this->response->setStatusCode(404);
        }

        $tripModel->delete($id);

        return $this->response->setJSON(['deleted' => true]);
    }

```

- [ ] **Step 5: `trip-detail.php` 뷰 생성(편집 UI, 공유 메뉴는 Task 8 에서 추가)**

`app/Views/trip-detail.php`:

```php
<?php

declare(strict_types=1);

/**
 * 여행 상세/편집 — 제목·설명·기간·커버 수정, 포함된 날짜 목록.
 *
 * @var int    $tripId
 * @var string $tripsUrl
 * @var string $uploadUrl
 * @var string $mapUrl
 * @var string $logoutUrl
 */
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>여행 상세 — Iter</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="stylesheet" href="/assets/nav.css">
    <style>
        html, body { margin: 0; font-family: system-ui, sans-serif; color: #222; }
        main { padding: 40px 20px; max-width: 720px; margin: 0 auto; }
        .header-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; }
        .header-row h1 { font-size: 20px; margin: 0; }
        .header-actions { display: flex; gap: 8px; position: relative; }
        .btn {
            display: inline-block; padding: 7px 14px; border-radius: 8px; border: 1.5px solid transparent;
            background: #1a73e8; color: #fff; font-size: 13px; font-weight: 600; cursor: pointer;
        }
        .btn:disabled { background: #9db8e8; cursor: not-allowed; }
        .btn-secondary { background: #fff; color: #1a73e8; border-color: #c7d2e0; }
        .btn-danger { background: #fff; color: #c0392b; border-color: #e6b8b3; }
        .field-group {
            background: #fff; border: 1px solid #eee; border-radius: 12px; padding: 18px; margin-bottom: 20px;
        }
        .field-group label { display: block; font-size: 12px; color: #666; margin-bottom: 4px; }
        .field-group input, .field-group textarea {
            width: 100%; box-sizing: border-box; border: 1px solid #ccd6f0; border-radius: 6px;
            font: inherit; padding: 7px 10px; margin-bottom: 12px;
        }
        .date-row { display: flex; gap: 12px; }
        .date-row > div { flex: 1; }
        .cover-picker { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
        .cover-option { position: relative; cursor: pointer; }
        .cover-option img { width: 72px; height: 72px; object-fit: cover; border-radius: 8px; display: block; border: 3px solid transparent; }
        .cover-option.selected img { border-color: #1a73e8; }
        .day-list { list-style: none; padding: 0; margin: 0; }
        .day-list li {
            display: flex; align-items: center; justify-content: space-between;
            padding: 10px 0; border-bottom: 1px solid #f0f0f0; font-size: 13px;
        }
        .day-list a { color: #1a73e8; text-decoration: none; font-size: 12px; }
        .save-feedback { font-size: 12px; color: #777; margin-left: 8px; }
    </style>
</head>
<body>
    <?= view('partials/nav', ['uploadUrl' => $uploadUrl, 'mapUrl' => $mapUrl, 'tripsUrl' => $tripsUrl, 'logoutUrl' => $logoutUrl]) ?>
    <main data-trips-url="<?= esc($tripsUrl, 'attr') ?>" data-trip-id="<?= (int) $tripId ?>">
        <div class="header-row">
            <h1 id="trip-title-heading">여행</h1>
            <div class="header-actions">
                <button type="button" class="btn btn-danger" id="delete-btn">삭제</button>
            </div>
        </div>

        <div class="field-group">
            <label for="trip-title">제목</label>
            <input type="text" id="trip-title" maxlength="100">

            <label for="trip-body">설명</label>
            <textarea id="trip-body" maxlength="2000" rows="3"></textarea>

            <div class="date-row">
                <div>
                    <label for="trip-start">시작일</label>
                    <input type="date" id="trip-start">
                </div>
                <div>
                    <label for="trip-end">종료일</label>
                    <input type="date" id="trip-end">
                </div>
            </div>

            <label>커버 사진</label>
            <div class="cover-picker" id="cover-picker"></div>

            <button type="button" class="btn" id="save-btn">저장</button>
            <span class="save-feedback" id="save-feedback"></span>
        </div>

        <div class="field-group">
            <label>포함된 날짜</label>
            <ul class="day-list" id="day-list"></ul>
        </div>
    </main>

    <script>
        (function () {
            var mainEl = document.querySelector('main');
            var tripsUrl = mainEl.dataset.tripsUrl;
            var tripId = mainEl.dataset.tripId;
            var tripUrl = tripsUrl + '/' + tripId;

            var titleEl = document.getElementById('trip-title');
            var bodyEl = document.getElementById('trip-body');
            var startEl = document.getElementById('trip-start');
            var endEl = document.getElementById('trip-end');
            var coverPickerEl = document.getElementById('cover-picker');
            var dayListEl = document.getElementById('day-list');
            var headingEl = document.getElementById('trip-title-heading');
            var saveFeedbackEl = document.getElementById('save-feedback');
            var selectedCoverId = null;

            fetch(tripUrl + '/data', { headers: { Accept: 'application/json' } })
                .then(function (res) { return res.json(); })
                .then(render)
                .catch(function () { headingEl.textContent = '여행을 불러오지 못했습니다.'; });

            function render(data) {
                var trip = data.trip;
                headingEl.textContent = trip.title;
                titleEl.value = trip.title;
                bodyEl.value = trip.body;
                startEl.value = trip.start_date;
                endEl.value = trip.end_date;
                selectedCoverId = trip.cover_photo_id;

                renderCoverPicker(data.days);
                renderDayList(data.days);
            }

            function renderCoverPicker(days) {
                coverPickerEl.innerHTML = '';
                days.forEach(function (day) {
                    if (!day.first_thumbnail_url) { return; }

                    var optionEl = document.createElement('div');
                    optionEl.className = 'cover-option' + (day.first_photo_id === selectedCoverId ? ' selected' : '');
                    optionEl.dataset.photoId = day.first_photo_id;

                    var img = document.createElement('img');
                    img.src = day.first_thumbnail_url;
                    img.alt = day.date;
                    img.title = day.date;
                    optionEl.appendChild(img);

                    optionEl.addEventListener('click', function () {
                        selectedCoverId = day.first_photo_id;
                        coverPickerEl.querySelectorAll('.cover-option').forEach(function (el) { el.classList.remove('selected'); });
                        optionEl.classList.add('selected');
                    });

                    coverPickerEl.appendChild(optionEl);
                });
            }

            function renderDayList(days) {
                dayListEl.innerHTML = '';
                days.forEach(function (day) {
                    var li = document.createElement('li');

                    var label = document.createElement('span');
                    label.textContent = day.date + ' · 사진 ' + day.photo_count + '장';
                    li.appendChild(label);

                    var link = document.createElement('a');
                    link.href = '/map?date=' + encodeURIComponent(day.date);
                    link.textContent = '이 날 시간표 보기';
                    li.appendChild(link);

                    dayListEl.appendChild(li);
                });
            }

            document.getElementById('save-btn').addEventListener('click', function () {
                var btn = this;
                btn.disabled = true;
                saveFeedbackEl.textContent = '저장 중…';

                var fields = {
                    title: titleEl.value.trim(),
                    body: bodyEl.value.trim(),
                    start_date: startEl.value,
                    end_date: endEl.value
                };
                if (selectedCoverId) { fields.cover_photo_id = String(selectedCoverId); }

                fetch(tripUrl + '/update', {
                    method: 'POST',
                    headers: { Accept: 'application/json' },
                    body: new URLSearchParams(fields)
                })
                    .then(function (res) {
                        if (!res.ok) { return res.json().then(function (b) { throw new Error(b.error || '저장 실패'); }); }
                        return res.json();
                    })
                    .then(function () {
                        headingEl.textContent = fields.title;
                        saveFeedbackEl.textContent = '저장됨';
                    })
                    .catch(function (err) {
                        saveFeedbackEl.textContent = err.message || '저장 실패';
                    })
                    .then(function () {
                        btn.disabled = false;
                        setTimeout(function () { saveFeedbackEl.textContent = ''; }, 2000);
                    });
            });

            document.getElementById('delete-btn').addEventListener('click', function () {
                if (!window.confirm('이 여행을 삭제할까요? 사진·시간표는 그대로 남고, 여행 그룹만 해제됩니다.')) { return; }

                fetch(tripUrl + '/delete', { method: 'POST', headers: { Accept: 'application/json' } })
                    .then(function (res) {
                        if (!res.ok) { throw new Error('delete failed'); }
                        window.location.href = tripsUrl;
                    })
                    .catch(function () { alert('삭제에 실패했습니다.'); });
            });
        })();
    </script>
</body>
</html>
```

- [ ] **Step 6: GREEN 확인**

Run: `vendor/bin/phpunit --no-coverage tests/feature/TripControllerTest.php`
Expected: `OK (19 tests, ...)`

- [ ] **Step 7: 전체 게이트 확인 + 커밋**

Run: `composer ci`
Expected: 그린.

```bash
git add app/Controllers/TripController.php app/Views/trip-detail.php app/Config/Routes.php tests/feature/TripControllerTest.php
git commit -m "✨ feat: 여행 상세·수정·삭제 API + 편집 화면 추가"
```

---

## Task 8: `TripController` — 공유 + `trip-detail.php`에 공유 메뉴 추가

**Files:**
- Modify: `app/Controllers/TripController.php`(`delete()` 메서드 뒤, `validateTripFields()` 앞에 `share()` 삽입)
- Modify: `app/Views/trip-detail.php`(헤더에 공유 버튼·메뉴 추가, JS 에 공유 로직 추가)
- Modify: `tests/feature/TripControllerTest.php`(파일 끝 `}` 앞에 테스트 추가)
- Modify: `app/Config/Routes.php`(여행 라우트 블록에 공유 라우트 추가)

**Interfaces:**
- Consumes: `App\Models\TripShareLinkModel::createOrGet(int $tripId): string`(Task 2).
- Produces: `TripController::share(int $id)`(POST /trips/{id}/share → `{url}`).

- [ ] **Step 1: 테스트 추가(RED)**

`tests/feature/TripControllerTest.php`의 마지막 `}` 바로 앞에 추가:

```php
    // ── POST /trips/{id}/share ───────────────────────────────────────

    public function testShareRequiresLogin(): void
    {
        $result = $this->post('trips/1/share');

        $result->assertStatus(401);
    }

    public function testShareReturns404WhenNotOwned(): void
    {
        $otherId = (new UserModel())->upsertByGoogleSub('sub-tripc-5', 'tripc5@example.com', 'TripC5');
        $tripId = (int) (new TripModel())->insert([
            'user_id' => $otherId, 'title' => 'T', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-16', 'cover_photo_id' => null,
        ]);

        $result = $this->withSession(['user_id' => $this->userId])->post('trips/' . $tripId . '/share');

        $result->assertStatus(404);
    }

    public function testShareReturnsShareUrl(): void
    {
        $tripId = (int) (new TripModel())->insert([
            'user_id' => $this->userId, 'title' => '서울 여행', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-16', 'cover_photo_id' => null,
        ]);

        $result = $this->withSession(['user_id' => $this->userId])->post('trips/' . $tripId . '/share');

        $result->assertStatus(200);
        $data = json_decode($result->getJSON() ?? '', true);
        $this->assertMatchesRegularExpression('#/t/[0-9a-f]{32}$#', $data['url']);
        $this->seeInDatabase('trip_share_links', ['trip_id' => $tripId]);
    }
```

- [ ] **Step 2: RED 확인**

Run: `vendor/bin/phpunit --no-coverage tests/feature/TripControllerTest.php`
Expected: 신규 3건 실패(`Can't find a route`), 기존 19건은 통과.

- [ ] **Step 3: 라우트 추가**

`app/Config/Routes.php`의 `$routes->post('trips/(:num)/delete', ...)` 바로 뒤에 추가:

```php
$routes->post('trips/(:num)/share', 'TripController::share/$1', ['filter' => 'sessionRateLimit:trips,120']);
```

- [ ] **Step 4: `TripController::share()` 추가**

`app/Controllers/TripController.php`에서 `delete()` 메서드가 끝나는 `}` 바로 뒤, `validateTripFields()` 앞에 삽입:

```php
    /**
     * 여행 SNS 공유 링크 생성(POST /trips/{id}/share). 같은 여행을 다시 공유해도
     * 기존 링크를 재사용한다(이미 퍼진 링크가 깨지지 않도록).
     */
    public function share(int $id): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => '로그인이 필요합니다.']);
        }

        if (model(TripModel::class)->findOwned($id, $userId) === null) {
            return $this->response->setStatusCode(404);
        }

        $token = model(\App\Models\TripShareLinkModel::class)->createOrGet($id);

        helper('url');

        return $this->response->setJSON(['url' => site_url('t/' . $token)]);
    }

```

파일 상단 `use` 블록에 `use App\Models\TripShareLinkModel;`을 추가하고(`use App\Models\TripModel;` 뒤), 위 메서드 본문의 `\App\Models\TripShareLinkModel::class`를 `TripShareLinkModel::class`로 바꾼다(전체 경로 표기는 임시 표기이며 최종 코드는 짧은 클래스명을 쓴다).

- [ ] **Step 5: `trip-detail.php`에 공유 메뉴 추가**

`app/Views/trip-detail.php`의 `.header-actions` 안, `<button type="button" class="btn btn-danger" id="delete-btn">삭제</button>` 앞에 삽입:

```php
                <button type="button" class="btn btn-secondary" id="share-toggle">🔗 공유</button>
                <div id="share-menu" hidden>
                    <button type="button" class="share-option" data-share="x">X(트위터)</button>
                    <button type="button" class="share-option" data-share="facebook">페이스북</button>
                    <button type="button" class="share-option" data-share="kakao">카카오톡</button>
                    <button type="button" class="share-option" data-share="instagram">인스타그램</button>
                    <button type="button" class="share-option" data-share="copy">링크 복사</button>
                </div>
```

`<style>` 블록의 `.save-feedback { ... }` 규칙 뒤에 추가:

```css
        #share-menu {
            position: absolute; top: calc(100% + 6px); right: 0; z-index: 10;
            background: #fff; border: 1px solid #e2e2e2; border-radius: 10px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.12); padding: 6px; min-width: 140px;
        }
        .share-option {
            display: block; width: 100%; text-align: left; border: none; background: none;
            padding: 8px 10px; font-size: 13px; color: #333; cursor: pointer; border-radius: 6px;
        }
        .share-option:hover { background: #f4f6fb; }
```

`<script>` 블록의 마지막(`document.getElementById('delete-btn').addEventListener(...)` 뒤, IIFE 를 닫는 `})();` 앞)에 추가:

```javascript
            // ── SNS 공유(시간표 공유 메뉴와 동일 패턴, 대상만 여행 단위) ──

            var shareToggleEl = document.getElementById('share-toggle');
            var shareMenuEl = document.getElementById('share-menu');
            var shareUrlCache = null;

            shareToggleEl.addEventListener('click', function (evt) {
                evt.stopPropagation();
                shareMenuEl.hidden = !shareMenuEl.hidden;
            });
            document.addEventListener('click', function (evt) {
                if (!shareMenuEl.hidden && !evt.target.closest('#share-toggle') && !evt.target.closest('#share-menu')) {
                    shareMenuEl.hidden = true;
                }
            });
            shareMenuEl.addEventListener('click', function (evt) {
                var btn = evt.target.closest('.share-option');
                if (btn) { handleShareOption(btn.dataset.share); }
            });

            function getShareUrl() {
                if (shareUrlCache) { return Promise.resolve(shareUrlCache); }

                return fetch(tripUrl + '/share', { method: 'POST', headers: { Accept: 'application/json' } })
                    .then(function (res) {
                        if (!res.ok) { throw new Error('공유 링크 생성 실패'); }
                        return res.json();
                    })
                    .then(function (data) {
                        shareUrlCache = data.url;
                        return data.url;
                    });
            }

            function handleShareOption(kind) {
                getShareUrl().then(function (url) {
                    var title = headingEl.textContent;

                    if (kind === 'x') {
                        window.open('https://twitter.com/intent/tweet?url=' + encodeURIComponent(url) + '&text=' + encodeURIComponent(title), '_blank', 'noopener,width=560,height=480');
                    } else if (kind === 'facebook') {
                        window.open('https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(url), '_blank', 'noopener,width=560,height=480');
                    } else if (kind === 'kakao' || kind === 'instagram') {
                        if (navigator.share) {
                            navigator.share({ title: title, url: url }).catch(function () {});
                        } else {
                            copyToClipboard(url);
                            alert((kind === 'kakao' ? '카카오톡은' : '인스타그램은') + ' 이 브라우저에서 바로 공유할 수 없어 링크를 복사했어요. 앱에 붙여넣어 공유해보세요.');
                        }
                    } else if (kind === 'copy') {
                        copyToClipboard(url);
                        alert('링크가 복사되었습니다.');
                    }

                    shareMenuEl.hidden = true;
                }).catch(function () {
                    alert('공유 링크를 만들지 못했습니다.');
                });
            }

            function copyToClipboard(text) {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text);
                    return;
                }
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); } catch (e) { /* 무시 */ }
                document.body.removeChild(ta);
            }
```

- [ ] **Step 6: GREEN 확인**

Run: `vendor/bin/phpunit --no-coverage tests/feature/TripControllerTest.php`
Expected: `OK (22 tests, ...)`

- [ ] **Step 7: 전체 게이트 확인 + 커밋**

Run: `composer ci`
Expected: 그린.

```bash
git add app/Controllers/TripController.php app/Views/trip-detail.php app/Config/Routes.php tests/feature/TripControllerTest.php
git commit -m "✨ feat: 여행 단위 SNS 공유 링크 생성 API + 공유 메뉴 UI 추가"
```

---

## Task 9: `TripShareController` + `trip-share.php` 뷰 + 라우트

**Files:**
- Create: `app/Controllers/TripShareController.php`
- Create: `app/Views/trip-share.php`
- Create: `tests/feature/TripShareControllerTest.php`
- Modify: `app/Config/Routes.php`(파일 끝, `// 계정·데이터 삭제` 블록 앞)

**Interfaces:**
- Consumes: `App\Models\TripShareLinkModel::findByToken(string $token): ?int`(Task 2), `App\Models\TripModel`(base `find()`), `service('tripSummary')::buildDaySummaries()`(Task 4), `App\Models\PhotoLocationModel::findOwned()`(기존), `App\Support\TimeConverter`.
- Produces: `TripShareController::show(string $token)`(GET /t/{token}), `::thumbnail(string $token, int $id)`(GET /t/{token}/thumbnails/{id}).

- [ ] **Step 1: 테스트 작성(RED)**

`tests/feature/TripShareControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PhotoLocationModel;
use App\Models\TripModel;
use App\Models\TripShareLinkModel;
use App\Models\UserModel;
use App\Services\Ingest\PhotoLocation;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class TripShareControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $refresh = true;
    protected $namespace = 'App';

    private int $userId;
    private int $tripId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (new UserModel())->upsertByGoogleSub('sub-tripsharec', 'tripsharec@example.com', 'TripShareC');
        $this->tripId = (int) (new TripModel())->insert([
            'user_id' => $this->userId, 'title' => '서울 여행', 'body' => '고궁 투어',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-16', 'cover_photo_id' => null,
        ]);
    }

    public function testShowReturns404ForUnknownToken(): void
    {
        $result = $this->get('t/' . str_repeat('0', 32));

        $result->assertStatus(404);
    }

    public function testShowOpensWithoutLoginAndShowsSummary(): void
    {
        (new PhotoLocationModel())->saveMany($this->userId, [
            new PhotoLocation('t1', 37.5, 127.0, '2024-03-15 02:05:00'), // KST 3/15 11:05
        ]);
        $token = (new TripShareLinkModel())->createOrGet($this->tripId);

        $result = $this->get('t/' . $token);

        $result->assertStatus(200);
        $body = html_entity_decode((string) $result->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringContainsString('서울 여행', $body);
        $this->assertStringContainsString('고궁 투어', $body);
        $this->assertStringContainsString('3월 15일', $body);
        $this->assertStringContainsString('og:title', $body);
    }

    public function testThumbnailServesOwnerPhotoWithinTripRange(): void
    {
        $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_') . '.jpg';
        file_put_contents($thumbPath, 'trip-shared-bytes');
        (new PhotoLocationModel())->saveMany($this->userId, [
            new PhotoLocation('t1', 37.5, 127.0, '2024-03-15 02:05:00', $thumbPath),
        ]);
        $photoId = (int) (new PhotoLocationModel())->where('source_item_id', 't1')->first()['id'];
        $token = (new TripShareLinkModel())->createOrGet($this->tripId);

        try {
            $result = $this->get('t/' . $token . '/thumbnails/' . $photoId);

            $result->assertStatus(200);
            $this->assertSame('trip-shared-bytes', $result->response()->getBody());
        } finally {
            if (is_file($thumbPath)) {
                unlink($thumbPath);
            }
        }
    }

    public function testThumbnailRejectsPhotoOutsideTripRange(): void
    {
        $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_') . '.jpg';
        file_put_contents($thumbPath, 'outside-range');
        (new PhotoLocationModel())->saveMany($this->userId, [
            new PhotoLocation('out', 37.5, 127.0, '2024-03-25 02:05:00', $thumbPath),
        ]);
        $photoId = (int) (new PhotoLocationModel())->where('source_item_id', 'out')->first()['id'];
        $token = (new TripShareLinkModel())->createOrGet($this->tripId);

        try {
            $result = $this->get('t/' . $token . '/thumbnails/' . $photoId);

            $result->assertStatus(404);
        } finally {
            if (is_file($thumbPath)) {
                unlink($thumbPath);
            }
        }
    }

    public function testThumbnailRejectsOtherUsersPhoto(): void
    {
        $otherId = (new UserModel())->upsertByGoogleSub('sub-tripsharec-2', 'tripsharec2@example.com', 'TripShareC2');
        $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_') . '.jpg';
        file_put_contents($thumbPath, 'not-mine');
        (new PhotoLocationModel())->saveMany($otherId, [
            new PhotoLocation('theirs', 37.5, 127.0, '2024-03-15 02:05:00', $thumbPath),
        ]);
        $photoId = (int) (new PhotoLocationModel())->where('source_item_id', 'theirs')->first()['id'];
        $token = (new TripShareLinkModel())->createOrGet($this->tripId);

        try {
            $result = $this->get('t/' . $token . '/thumbnails/' . $photoId);

            $result->assertStatus(404);
        } finally {
            if (is_file($thumbPath)) {
                unlink($thumbPath);
            }
        }
    }
}
```

- [ ] **Step 2: RED 확인**

Run: `vendor/bin/phpunit --no-coverage tests/feature/TripShareControllerTest.php`
Expected: ERRORS — 라우트 없음 / `Class "App\Controllers\TripShareController" not found`.

- [ ] **Step 3: `TripShareController` 구현**

`app/Controllers/TripShareController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\PhotoLocationModel;
use App\Models\TripModel;
use App\Models\TripShareLinkModel;
use App\Support\TimeConverter;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * 여행 SNS 공유 — 비로그인 공개 열람.
 *
 * 토큰은 여행 단위로 발급되며(TripController::share), 이 컨트롤러는 토큰이 가리키는
 * 여행의 날짜 범위로 스코프를 좁혀 요약(날짜·사진 그리드)만 읽기 전용으로 노출한다.
 * 시간대별 세부 정보(POI·메모)는 포함하지 않는다.
 */
class TripShareController extends BaseController
{
    /**
     * 공유된 여행 요약 페이지(GET /t/{token}).
     */
    public function show(string $token): ResponseInterface|string
    {
        $tripId = model(TripShareLinkModel::class)->findByToken($token);
        if ($tripId === null) {
            return $this->response->setStatusCode(404);
        }

        $trip = model(TripModel::class)->find($tripId);
        if ($trip === null) {
            return $this->response->setStatusCode(404);
        }

        $userId = (int) $trip['user_id'];
        $summaries = service('tripSummary')->buildDaySummaries($userId, (string) $trip['start_date'], (string) $trip['end_date']);

        $days = [];
        foreach ($summaries as $summary) {
            $thumbnailUrls = [];
            foreach ($summary['thumbnail_ids'] as $photoId) {
                $thumbnailUrls[] = "/t/{$token}/thumbnails/{$photoId}";
            }

            $days[] = [
                'date' => $summary['date'],
                'photo_count' => $summary['photo_count'],
                'thumbnail_urls' => $thumbnailUrls,
            ];
        }

        return view('trip-share', [
            'trip' => [
                'title' => (string) $trip['title'],
                'body' => (string) ($trip['body'] ?? ''),
                'start_date' => (string) $trip['start_date'],
                'end_date' => (string) $trip['end_date'],
            ],
            'days' => $days,
        ]);
    }

    /**
     * 공유된 여행에 속한 썸네일(GET /t/{token}/thumbnails/{id}).
     *
     * 토큰이 가리키는 여행의 날짜 범위·소유자를 벗어난 사진은 존재를 노출하지 않기 위해
     * 404 로 응답한다.
     */
    public function thumbnail(string $token, int $id): ResponseInterface
    {
        $tripId = model(TripShareLinkModel::class)->findByToken($token);
        if ($tripId === null) {
            return $this->response->setStatusCode(404);
        }

        $trip = model(TripModel::class)->find($tripId);
        if ($trip === null) {
            return $this->response->setStatusCode(404);
        }

        $photo = model(PhotoLocationModel::class)->findOwned($id, (int) $trip['user_id']);
        if ($photo === null) {
            return $this->response->setStatusCode(404);
        }

        $takenAtDate = substr(TimeConverter::utcToKst((string) ($photo['taken_at'] ?? '')), 0, 10);
        if ($takenAtDate < (string) $trip['start_date'] || $takenAtDate > (string) $trip['end_date']) {
            return $this->response->setStatusCode(404); // 여행 기간 밖의 사진.
        }

        $path = (string) ($photo['thumbnail_path'] ?? '');
        if ($path === '' || ! is_file($path)) {
            return $this->response->setStatusCode(404);
        }

        return $this->response
            ->removeHeader('Cache-Control')
            ->setHeader('Cache-Control', 'private, max-age=86400')
            ->setContentType('image/jpeg')
            ->setBody((string) file_get_contents($path));
    }
}
```

- [ ] **Step 4: `trip-share.php` 뷰 생성**

`app/Views/trip-share.php`:

```php
<?php

declare(strict_types=1);

/**
 * 공유된 여행 — 비로그인 공개 열람용 읽기 전용 요약 페이지.
 *
 * @var array{title: string, body: string, start_date: string, end_date: string} $trip
 * @var list<array{date: string, photo_count: int, thumbnail_urls: list<string>}> $days
 */

$startParts = explode('-', $trip['start_date']);
$endParts = explode('-', $trip['end_date']);
$rangeLabel = $trip['start_date'] === $trip['end_date']
    ? $startParts[0] . '년 ' . (int) $startParts[1] . '월 ' . (int) $startParts[2] . '일'
    : $startParts[0] . '년 ' . (int) $startParts[1] . '월 ' . (int) $startParts[2] . '일 ~ '
        . (($startParts[0] !== $endParts[0]) ? $endParts[0] . '년 ' : '') . (int) $endParts[1] . '월 ' . (int) $endParts[2] . '일';
$title = $trip['title'] !== '' ? $trip['title'] : $rangeLabel . ' 여행';
$description = $trip['body'] !== '' ? $trip['body'] : $rangeLabel . '의 여행 기록을 확인해보세요.';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($title) ?> — Iter</title>
    <meta name="robots" content="noindex">
    <meta property="og:type" content="article">
    <meta property="og:title" content="<?= esc($title, 'attr') ?>">
    <meta property="og:description" content="<?= esc($description, 'attr') ?>">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <style>
        html, body { margin: 0; font-family: system-ui, sans-serif; background: #f4f5f7; color: #222; }
        .wrap { max-width: 720px; margin: 0 auto; padding: 28px 20px 60px; }
        .brand { font-size: 13px; color: #888; margin-bottom: 18px; }
        .trip-header { background: #fff; border: 1px solid #e5e5e5; border-radius: 12px; padding: 18px 20px; margin-bottom: 22px; }
        .trip-header h1 { margin: 0 0 6px; font-size: 20px; }
        .trip-header .range { font-size: 13px; color: #777; margin-bottom: 8px; }
        .trip-header p { margin: 0; color: #555; white-space: pre-wrap; }
        .day-section { margin-bottom: 26px; }
        .day-section h2 { font-size: 15px; margin: 0 0 8px; color: #1a73e8; }
        .day-photos { display: flex; flex-wrap: wrap; gap: 8px; }
        .day-photos img { width: 110px; height: 110px; object-fit: cover; border-radius: 8px; }
        .empty { color: #777; padding: 30px 0; text-align: center; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="brand">Iter 로 만든 여행 기록</div>
        <div class="trip-header">
            <h1><?= esc($title) ?></h1>
            <div class="range"><?= esc($rangeLabel) ?></div>
            <?php if ($trip['body'] !== '') : ?>
                <p><?= esc($trip['body']) ?></p>
            <?php endif; ?>
        </div>

        <?php if ($days === []) : ?>
            <div class="empty">표시할 사진이 없습니다.</div>
        <?php endif; ?>

        <?php foreach ($days as $day) : ?>
            <div class="day-section">
                <?php $dp = explode('-', $day['date']); ?>
                <h2><?= (int) $dp[1] ?>월 <?= (int) $dp[2] ?>일 · 사진 <?= (int) $day['photo_count'] ?>장</h2>
                <?php if ($day['thumbnail_urls'] !== []) : ?>
                    <div class="day-photos">
                        <?php foreach ($day['thumbnail_urls'] as $url) : ?>
                            <img src="<?= esc($url, 'attr') ?>" alt="" loading="lazy">
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</body>
</html>
```

- [ ] **Step 5: 라우트 추가**

`app/Config/Routes.php`의 `// 계정·데이터 삭제` 줄 바로 앞에 추가:

```php
// 여행 단위 SNS 공유 링크(비로그인 공개 열람)
$routes->get('t/(:segment)', 'TripShareController::show/$1');
$routes->get('t/(:segment)/thumbnails/(:num)', 'TripShareController::thumbnail/$1/$2');

```

- [ ] **Step 6: GREEN 확인**

Run: `vendor/bin/phpunit --no-coverage tests/feature/TripShareControllerTest.php`
Expected: `OK (5 tests, ...)`

- [ ] **Step 7: 전체 게이트 확인 + 커밋**

Run: `composer ci`
Expected: 그린.

```bash
git add app/Controllers/TripShareController.php app/Views/trip-share.php app/Config/Routes.php tests/feature/TripShareControllerTest.php
git commit -m "✨ feat: 여행 공개 공유 페이지·썸네일 엔드포인트 추가"
```

---

## Task 10: 내비게이션 "내 여행" 링크 추가

**Files:**
- Modify: `app/Views/partials/nav.php`
- Modify: `app/Views/map.php`(nav 호출 라인, 현재 218행)
- Modify: `app/Views/upload.php`(nav 호출 라인, 현재 113행)
- Modify: `app/Views/landing.php`(nav 호출 라인, 현재 75행 + 문서블록)
- Modify: `app/Controllers/RouteController.php`(`map()` 메서드의 `view()` 호출)
- Modify: `app/Controllers/TakeoutController.php`(`form()` 메서드의 `view()` 호출)
- Modify: `app/Controllers/Home.php`(`index()` 메서드의 `view()` 호출)
- Modify: `tests/feature/RouteControllerTest.php`(`testMapPageIncludesLoggedInNav`)
- Modify: `tests/feature/TakeoutControllerTest.php`(`testFormRendersUploadPageWhenLoggedIn`)
- Modify: `tests/feature/HomeControllerTest.php`(`testShowsMenuAndHidesLoginCtaWhenLoggedIn`)

**Interfaces:**
- Consumes: `TripController::index()`(Task 6, `/trips` 라우트).
- Produces: 모든 로그인 상태 상단 메뉴에 "내 여행" 링크 노출.

- [ ] **Step 1: 기존 nav 테스트에 어서션 추가(RED)**

`tests/feature/RouteControllerTest.php`의 `testMapPageIncludesLoggedInNav` 메서드를 다음으로 교체:

```php
    public function testMapPageIncludesLoggedInNav(): void
    {
        // 로그인 후 상단 메뉴(사진 가져오기·지도 보기·내 여행·로그아웃)는 업로드 페이지뿐 아니라
        // 지도 페이지에서도 동일하게 보여야 한다.
        $userId = (new UserModel())->upsertByGoogleSub('sub-map-nav', 'mapnav@example.com', 'MapNav');

        $result = $this->withSession(['user_id' => $userId])->get('map');

        $result->assertStatus(200);
        $body = html_entity_decode((string) $result->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringContainsString('class="brand"', $body);
        $this->assertStringContainsString('지도 보기', $body);
        $this->assertStringContainsString('내 여행', $body);
        $this->assertStringContainsString('/trips', $body);
        $this->assertStringContainsString('로그아웃', $body);
        $this->assertStringContainsString('/auth/logout', $body);
    }
```

`tests/feature/TakeoutControllerTest.php`의 `testFormRendersUploadPageWhenLoggedIn` 메서드 안, `$this->assertStringContainsString('지도 보기', $body);` 바로 뒤에 추가:

```php
        $this->assertStringContainsString('내 여행', $body);
```

`tests/feature/HomeControllerTest.php`의 `testShowsMenuAndHidesLoginCtaWhenLoggedIn` 메서드 안, `$this->assertStringContainsString('지도 보기', $body);` 바로 뒤에 추가:

```php
        $this->assertStringContainsString('내 여행', $body);
```

- [ ] **Step 2: RED 확인**

Run: `vendor/bin/phpunit --no-coverage tests/feature/RouteControllerTest.php tests/feature/TakeoutControllerTest.php tests/feature/HomeControllerTest.php`
Expected: 3건 실패 — "내 여행" 텍스트가 본문에 없음.

- [ ] **Step 3: `nav.php` 수정**

`app/Views/partials/nav.php` 전체를 다음으로 교체:

```php
<?php

declare(strict_types=1);

/**
 * 로그인 후 상단 메뉴 — 로그인 상태로 렌더되는 모든 페이지(사진 가져오기·지도 등)가 공유한다.
 *
 * @var string $uploadUrl
 * @var string $mapUrl
 * @var string $tripsUrl
 * @var string $logoutUrl
 */
?>
<nav>
    <a href="/" class="brand"><img src="/assets/logo-mark-512.png" alt="Iter"></a>
    <a href="<?= esc($uploadUrl, 'attr') ?>">사진 가져오기</a>
    <a href="<?= esc($mapUrl, 'attr') ?>">지도 보기</a>
    <a href="<?= esc($tripsUrl, 'attr') ?>">내 여행</a>
    <a href="<?= esc($logoutUrl, 'attr') ?>">로그아웃</a>
    <span class="spacer"></span>
    <span class="legal">
        <a href="/privacy-policy.html">개인정보처리방침</a>
        <a href="/terms-of-service.html">서비스 이용약관</a>
    </span>
</nav>
```

- [ ] **Step 4: 세 개 뷰의 nav 호출부·문서블록 수정**

`app/Views/map.php` 218행:

```php
    <?= view('partials/nav', ['uploadUrl' => $uploadUrl, 'mapUrl' => $mapUrl, 'tripsUrl' => $tripsUrl, 'logoutUrl' => $logoutUrl]) ?>
```

`app/Views/map.php` 문서블록(6-14행)의 `@var string $mapUrl` 줄 뒤에 추가:

```php
 * @var string $tripsUrl
```

`app/Views/upload.php` 113행:

```php
    <?= view('partials/nav', ['uploadUrl' => $uploadUrl, 'mapUrl' => $mapUrl, 'tripsUrl' => $tripsUrl, 'logoutUrl' => $logoutUrl]) ?>
```

`app/Views/upload.php` 문서블록의 `@var string $mapUrl` 줄 뒤에 추가:

```php
 * @var string $tripsUrl
```

`app/Views/landing.php` 75행:

```php
    <?= view('partials/nav', ['uploadUrl' => $uploadUrl, 'mapUrl' => $mapUrl, 'tripsUrl' => $tripsUrl, 'logoutUrl' => $logoutUrl]) ?>
```

`app/Views/landing.php` 문서블록(9-13행)의 `@var string $mapUrl` 줄 뒤에 추가:

```php
 * @var string   $tripsUrl
```

- [ ] **Step 5: 세 컨트롤러의 `view()` 데이터에 `tripsUrl` 추가**

`app/Controllers/RouteController.php`의 `map()` 메서드 안 `view('map', [...])` 배열에서 `'timelineUrl' => site_url('timeline'),` 뒤에 추가:

```php
            'tripsUrl' => site_url('trips'),
```

`app/Controllers/TakeoutController.php`의 `form()` 메서드 안 `view('upload', [...])` 배열에서 `'mapUrl' => site_url('map'),` 뒤에 추가:

```php
            'tripsUrl' => site_url('trips'),
```

`app/Controllers/Home.php`의 `index()` 메서드 안 `view('landing', [...])` 배열에서 `'mapUrl' => site_url('map'),` 뒤에 추가:

```php
            'tripsUrl' => site_url('trips'),
```

- [ ] **Step 6: GREEN 확인**

Run: `vendor/bin/phpunit --no-coverage tests/feature/RouteControllerTest.php tests/feature/TakeoutControllerTest.php tests/feature/HomeControllerTest.php`
Expected: 전부 `OK`.

- [ ] **Step 7: 전체 게이트 확인 + 커밋**

Run: `composer ci`
Expected: 그린(레이아웃에 새 변수가 추가돼도 다른 페이지 렌더링에 영향 없어야 함).

```bash
git add app/Views/partials/nav.php app/Views/map.php app/Views/upload.php app/Views/landing.php \
        app/Controllers/RouteController.php app/Controllers/TakeoutController.php app/Controllers/Home.php \
        tests/feature/RouteControllerTest.php tests/feature/TakeoutControllerTest.php tests/feature/HomeControllerTest.php
git commit -m "✨ feat: 상단 메뉴에 \"내 여행\" 링크 추가"
```

---

## Task 11: 전체 검증 + 브라우저 런타임 확인

**Files:** 없음(검증 전용 태스크)

**Interfaces:** 없음.

- [ ] **Step 1: 전체 자동 테스트 그린 확인**

Run: `composer ci`
Expected: `[OK] No errors`(PHPStan), CS Fixer diff 없음, PHPUnit 전체 `OK (... tests, ... assertions)`.

- [ ] **Step 2: 로컬 SQLite 임시 DB로 런타임 구동 확인**

이 프로젝트는 `.env`를 임시로 SQLite3 로 바꿔 `php spark migrate` 후 `mcp__Claude_Browser__preview_start`(`.claude/launch.json`의 `iter-serve`)로 실제 화면을 확인하는 방식을 지금까지 반복 사용해왔다(세션 히스토리 참고). 원본 `.env`는 반드시 백업 후 검증이 끝나면 복원한다.

```bash
cp .env .env.bak-trip-verify
cat >> .env << 'EOF'

# TEMP trip-verify
app.baseURL = 'http://localhost:8299/'
database.default.database = dev-trip-verify.db
database.default.DBDriver = SQLite3
database.default.DBPrefix =
EOF
php spark migrate
```

Expected: `CreateTrips`, `CreateTripShareLinks` 마이그레이션이 `Running:`으로 표시되고 `Migrations complete.`.

- [ ] **Step 3: 시드 데이터 준비**

```bash
mkdir -p writable/uploads/thumbnails
php -r '
$colors = [[220,60,60],[60,140,220],[60,180,90]];
foreach ($colors as $i => $c) {
    $im = imagecreatetruecolor(300, 200);
    imagefill($im, 0, 0, imagecolorallocate($im, $c[0], $c[1], $c[2]));
    imagejpeg($im, "writable/uploads/thumbnails/verify-{$i}.jpg");
}'
sqlite3 writable/dev-trip-verify.db << EOF
INSERT INTO users (google_sub, email, name, created_at, updated_at) VALUES ('dev-sub', 'dev@example.com', 'Dev', datetime('now'), datetime('now'));
INSERT INTO photo_locations (user_id, source_item_id, lat, lng, thumbnail_path, taken_at, created_at) VALUES
 (1, 'p1', 37.5796, 126.9770, '$(pwd)/writable/uploads/thumbnails/verify-0.jpg', '2024-03-15 02:00:00', datetime('now')),
 (1, 'p2', 37.5665, 126.9780, '$(pwd)/writable/uploads/thumbnails/verify-1.jpg', '2024-03-16 02:00:00', datetime('now')),
 (1, 'p3', 35.1587, 129.1604, '$(pwd)/writable/uploads/thumbnails/verify-2.jpg', '2024-03-25 02:00:00', datetime('now'));
EOF
```

Expected: 명령이 오류 없이 완료됨(3장 시드 — 3/15~16 은 자동 제안 후보 1건, 3/25 는 3일 이상 공백이라 별도 제안 1건).

- [ ] **Step 4: 브라우저로 확인**

`mcp__Claude_Browser__preview_start`(`name: "iter-serve"`)로 서버를 띄우고, 새로 생긴 세션 파일에 `user_id|i:1;`을 주입한 뒤(이전 세션들과 동일한 방식), `/trips`에 접속해 다음을 확인한다:

1. "제안된 여행"에 카드 2개(3/15~16, 3/25)가 뜬다.
2. 3/15~16 카드의 "저장" → 제목 확인 후 "확정" → 상세 페이지(`/trips/{id}`)로 이동한다.
3. 상세 페이지에서 제목·설명을 수정하고 "저장"을 누르면 헤딩이 갱신된다.
4. 커버 사진 선택지(포함된 날짜의 대표 사진)가 보이고, 클릭하면 선택 표시(파란 테두리)가 바뀐다.
5. "이 날 시간표 보기" 링크가 `/map?date=2024-03-15`로 연결되고, 지도 페이지가 그 날짜를 선택한 상태로 열린다(Task 이전에 구현된 기능과의 통합 확인).
6. "🔗 공유" → "링크 복사" 클릭 시 `POST /trips/{id}/share` 요청이 나가고, 응답의 `url`(`/t/{token}` 형식)을 별도 탭에서 열면 **로그인 없이** 여행 요약(제목·설명·날짜별 사진 그리드)이 보인다.
7. 상세 페이지의 "삭제" → 확인 대화상자 → `/trips`로 돌아오고, 방금 삭제한 여행이 다시 "제안된 여행"에 나타난다(사진·시간표는 그대로 유지).

Expected: 위 7개 동작이 모두 화면·네트워크 요청으로 확인됨(`mcp__Claude_Browser__read_network_requests`로 상태코드 200 확인).

- [ ] **Step 5: 환경 원복**

```bash
mv .env.bak-trip-verify .env
rm -f writable/dev-trip-verify.db writable/uploads/thumbnails/verify-*.jpg
rm -f writable/session/ci_session*
```

Expected: `.env`가 원래 상태로 복원되고, 임시 DB·썸네일·세션 파일이 모두 제거됨. `git status --short`에 `.env` 변경이 남아있지 않아야 한다.

- [ ] **Step 6: 최종 확인**

Run: `git status --short`
Expected: 추적되지 않은 `.claude/`(세션 전용, 무시)를 제외하고 워킹트리 깨끗함 — 모든 변경은 이미 Task 1~10 에서 커밋됨.

이 태스크는 커밋할 코드 변경이 없다(검증 전용).

---

## Self-Review

**스펙 커버리지 확인:**
- 데이터 모델(`trips`, `trip_share_links`) → Task 1, 2. ✅
- 자동 제안 로직(3일 공백 규칙) → Task 5. ✅
- 화면 흐름(목록/상세/편집) → Task 6, 7. ✅
- API 엔드포인트 전체(`GET/POST /trips*`, `GET /t/*`) → Task 6, 7, 8, 9. ✅
- 공유(공개 페이지·썸네일 스코프) → Task 8, 9. ✅
- 검증 규칙(길이·기간 상한·겹침·커버 검증) → Task 6(`validateTripFields`/`validateCoverPhotoId`). ✅
- 테스트 전략(database/unit/feature 5개 파일) → Task 1, 2, 3, 4, 5, 6/7/8, 9 전부 대응. ✅
- 내비게이션 "내 여행" 진입점 → Task 10(스펙에는 명시 안 됐지만 화면 흐름상 필수 — 셀프리뷰에서 발견해 태스크로 추가함).

**플레이스홀더 스캔:** "TODO"/"TBD"/"적절히 처리" 류 문구 없음. 모든 코드 스텝에 완전한 코드 첨부.

**타입 일관성 확인:** `TripSummaryService::buildDaySummaries()`가 반환하는 `thumbnail_ids`(Task 4)를 `TripController::showData()`(Task 7)와 `TripShareController::show()`(Task 9)가 동일한 키 이름으로 소비함. `TripSuggestionService::suggest()`의 반환 키(`start_date`/`end_date`/`photo_count`/`suggested_title`/`first_photo_id`, Task 5)를 `TripController::data()`(Task 6)가 그대로 사용함. `TripModel::overlaps()`의 시그니처(`userId, startDate, endDate, ?excludeId`, Task 1)를 `TripController::create()`/`update()`(Task 6/7)가 일치하게 호출함. 모두 확인 완료.

---

Plan complete and saved to `docs/superpowers/plans/2026-07-22-trip-grouping.md`. Two execution options:

**1. Subagent-Driven (recommended)** - I dispatch a fresh subagent per task, review between tasks, fast iteration

**2. Inline Execution** - Execute tasks in this session using executing-plans, batch execution with checkpoints

**Which approach?**
