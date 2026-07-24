# 위치기록(Timeline.json) 지원 구현 계획

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 기기 내보내기 Timeline.json 을 업로드받아 다운샘플링해 저장하고, 메인 지도에서 날짜별 점선 트랙으로 겹쳐 보여준다.

**Architecture:** 새 테이블 `timeline_points`(UTC 저장). 순수 파서(`TimelineHistoryParser`)와 다운샘플러(`TimelineTrackDownsampler`)를 `LocationHistoryService` 가 조립. `LocationHistoryController` 가 업로드(`POST /location-history/upload`)·트랙 조회(`GET /location-history/track/{date}`)를 thin 하게 위임. upload 페이지 섹션 + map.php 토글 레이어.

**Tech Stack:** PHP 8.2+/CI4, MySQL, Leaflet. 신규 의존성 없음.

## Global Constraints

- 설계 문서: `docs/superpowers/specs/2026-07-23-location-history-design.md`.
- 작업 브랜치 `feature/location-history`(origin/dev 기준), PR 없이 dev 직접 머지. 경로는 `Iter/` 기준.
- **시각 저장은 UTC 'Y-m-d H:i:s'** (프로젝트 표준 — `App\Support\TimeConverter` 참조). KST 는 표시·날짜 그룹핑에서만.
- 상수 고정: 파일 상한 **64MB**(`64 * 1024 * 1024`), 다운샘플링 **60초 미만 && 10m 미만 스킵**, 트랙 스타일 **dashArray '4 6', weight 2, opacity 0.7**.
- `point` 문자열 형식: `"37.5665000°, 126.9780000°"` — 도 기호 제거 후 float. 위도 |90|·경도 |180| 초과는 불량으로 스킵.
- PHP: strict_types·PSR-12·한국어 주석·완전 타입. **`@phpstan-ignore` 절대 금지.** 뷰 JS 는 ES5.
- 테스트 디렉터리는 소문자 `tests/unit/`·`tests/database/`(저장소 관례).
- 도메인 예외 인프라가 없는 저장소이므로 서비스는 한국어 메시지의 `RuntimeException` 을 던지고 컨트롤러가 기존 패턴(JSON 에러)으로 변환한다.
- 검증: `composer ci` 통과 필수. 파서·다운샘플러·saveBatch 는 테스트 필수.

---

### Task 0: 작업 브랜치 생성

- [ ] **Step 1:**

```bash
cd /Users/jongwonbyun/claude-works/varius/Iter
git checkout dev && git pull origin dev && git checkout -b feature/location-history
```

---

### Task 1: timeline_points 테이블·모델 (TDD)

**Files:**
- Create: `app/Database/Migrations/2026-07-23-120000_CreateTimelinePoints.php`
- Create: `app/Models/TimelinePointModel.php`
- Create: `app/Services/Ingest/TimelinePoint.php` (DTO — 모델 saveBatch 가 소비)
- Test: `tests/database/TimelinePointModelTest.php`

**Interfaces:**
- Consumes: 없음
- Produces:
  - `TimelinePoint` DTO: `final readonly class TimelinePoint { public function __construct(public float $lat, public float $lng, public string $recordedAt) {} }` — `recordedAt` 은 UTC 'Y-m-d H:i:s'
  - `TimelinePointModel::saveBatch(int $userId, array $points): int` — `list<TimelinePoint>`, (user, recorded_at) 중복은 DB 기존행·배치 내 모두 스킵, 삽입 건수 반환
  - `TimelinePointModel::findTrackByUtcRange(int $userId, string $fromUtc, string $toUtc): list<array{lat: float, lng: float}>` — recorded_at 오름차순

- [ ] **Step 1: DTO 작성** — `app/Services/Ingest/TimelinePoint.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Ingest;

/**
 * 위치기록 한 점 — Timeline.json 의 timelinePath 항목 하나.
 *
 * recordedAt 은 UTC 'Y-m-d H:i:s'(프로젝트 저장 표준). KST 변환은 표시 단계에서만 한다.
 */
final readonly class TimelinePoint
{
    public function __construct(
        public float $lat,
        public float $lng,
        public string $recordedAt,
    ) {
    }
}
```

- [ ] **Step 2: 실패하는 DB 테스트 작성** — `tests/database/TimelinePointModelTest.php`
  (기존 `tests/database/PhotoLocationModelTest.php` 의 setUp/트레이트 패턴을 그대로 따른다 —
  파일을 먼저 읽고 사용자 생성 방식(`UserModel::upsertByGoogleSub` 등)을 동일하게 사용):

```php
<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\TimelinePointModel;
use App\Models\UserModel;
use App\Services\Ingest\TimelinePoint;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * timeline_points 저장·조회 검증 — 재업로드 중복 스킵과 범위 조회.
 */
final class TimelinePointModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (new UserModel())->upsertByGoogleSub('sub-tl', 'tl@example.com', 'TL');
    }

    public function testSaveBatchInsertsAndSkipsDuplicates(): void
    {
        $model = new TimelinePointModel();

        $first = $model->saveBatch($this->userId, [
            new TimelinePoint(37.5665, 126.9780, '2026-07-20 00:10:00'),
            new TimelinePoint(37.5700, 126.9850, '2026-07-20 00:20:00'),
            new TimelinePoint(37.5700, 126.9850, '2026-07-20 00:20:00'), // 배치 내 중복
        ]);
        $this->assertSame(2, $first);

        // 재업로드: 기존 2건 + 신규 1건
        $second = $model->saveBatch($this->userId, [
            new TimelinePoint(37.5665, 126.9780, '2026-07-20 00:10:00'),
            new TimelinePoint(37.5750, 126.9900, '2026-07-20 00:30:00'),
        ]);
        $this->assertSame(1, $second);

        $this->assertSame(3, $model->where('user_id', $this->userId)->countAllResults());
    }

    public function testSaveBatchWithEmptyListReturnsZero(): void
    {
        $this->assertSame(0, (new TimelinePointModel())->saveBatch($this->userId, []));
    }

    public function testFindTrackByUtcRangeReturnsOrderedFloats(): void
    {
        $model = new TimelinePointModel();
        $model->saveBatch($this->userId, [
            new TimelinePoint(37.5700, 126.9850, '2026-07-20 01:00:00'),
            new TimelinePoint(37.5665, 126.9780, '2026-07-20 00:10:00'),
            new TimelinePoint(37.5750, 126.9900, '2026-07-21 05:00:00'), // 범위 밖
        ]);

        // 다른 사용자 행은 제외돼야 한다.
        $otherId = (new UserModel())->upsertByGoogleSub('sub-tl-other', 'tl-other@example.com', 'TLO');
        $model->saveBatch($otherId, [new TimelinePoint(35.0, 129.0, '2026-07-20 00:15:00')]);

        $rows = $model->findTrackByUtcRange($this->userId, '2026-07-20 00:00:00', '2026-07-20 23:59:59');

        $this->assertCount(2, $rows);
        $this->assertIsFloat($rows[0]['lat']);
        $this->assertIsFloat($rows[0]['lng']);
        $this->assertSame(37.5665, $rows[0]['lat']); // 시간 오름차순 — 00:10 이 먼저
        $this->assertSame(37.5700, $rows[1]['lat']);
    }
}
```

- [ ] **Step 3: 실패 확인**

```bash
vendor/bin/phpunit tests/database/TimelinePointModelTest.php --no-coverage
```

Expected: FAIL — TimelinePointModel not found

- [ ] **Step 4: 마이그레이션 작성** — `app/Database/Migrations/2026-07-23-120000_CreateTimelinePoints.php`
  (forge 만 사용 — raw SQL 없음이므로 DBPrefix 이슈 없음):

```php
<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 위치기록(Timeline.json) 트랙 포인트 테이블.
 *
 * recorded_at 은 UTC(프로젝트 표준). (user_id, recorded_at) 유니크로 재업로드 idempotency 를
 * 보장하며, 이 유니크 인덱스가 날짜 범위 조회도 커버한다.
 */
final class CreateTimelinePoints extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'user_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'lat' => ['type' => 'DECIMAL', 'constraint' => '10,7'],
            'lng' => ['type' => 'DECIMAL', 'constraint' => '10,7'],
            'recorded_at' => ['type' => 'DATETIME'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['user_id', 'recorded_at'], 'uniq_timeline_points_user_time');
        $this->forge->addForeignKey('user_id', 'users', 'id', '', 'CASCADE');
        $this->forge->createTable('timeline_points', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('timeline_points', true);
    }
}
```

- [ ] **Step 5: 모델 작성** — `app/Models/TimelinePointModel.php`
  (`$useTimestamps` 등 공통 설정은 `PhotoLocationModel` 상단 설정을 읽고 동일하게 맞춘다):

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Ingest\TimelinePoint;
use CodeIgniter\Model;

/**
 * 위치기록 트랙 포인트 데이터 접근.
 */
class TimelinePointModel extends Model
{
    protected $table = 'timeline_points';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = [
        'user_id',
        'lat',
        'lng',
        'recorded_at',
        'created_at',
    ];
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = '';

    /**
     * 트랙 포인트를 저장한다. (user_id, recorded_at) 가 이미 있으면 건너뛴다(재업로드 idempotent).
     *
     * @param list<TimelinePoint> $points
     *
     * @return int 실제 삽입 건수
     */
    public function saveBatch(int $userId, array $points): int
    {
        if ($points === []) {
            return 0;
        }

        $times = array_map(static fn (TimelinePoint $p): string => $p->recordedAt, $points);

        // 들어온 범위의 기존 recorded_at 집합만 조회해 중복을 거른다.
        /** @var list<array{recorded_at: string}> $existingRows */
        $existingRows = $this->builder()
            ->select('recorded_at')
            ->where('user_id', $userId)
            ->where('recorded_at >=', min($times))
            ->where('recorded_at <=', max($times))
            ->get()->getResultArray();

        $seen = [];
        foreach ($existingRows as $row) {
            $seen[$row['recorded_at']] = true;
        }

        $rows = [];
        foreach ($points as $point) {
            if (isset($seen[$point->recordedAt])) {
                continue;
            }
            $seen[$point->recordedAt] = true; // 배치 내 중복도 한 번만

            $rows[] = [
                'user_id' => $userId,
                'lat' => $point->lat,
                'lng' => $point->lng,
                'recorded_at' => $point->recordedAt,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        return (int) $this->insertBatch($rows);
    }

    /**
     * UTC 시각 범위의 트랙 포인트를 시간 오름차순으로 조회한다(지도 트랙 렌더용).
     *
     * @return list<array{lat: float, lng: float}>
     */
    public function findTrackByUtcRange(int $userId, string $fromUtc, string $toUtc): array
    {
        /** @var list<array{lat: float|string, lng: float|string}> $rows */
        $rows = $this->builder()
            ->select('lat, lng')
            ->where('user_id', $userId)
            ->where('recorded_at >=', $fromUtc)
            ->where('recorded_at <=', $toUtc)
            ->orderBy('recorded_at', 'ASC')
            ->get()->getResultArray();

        return array_map(
            static fn (array $row): array => ['lat' => (float) $row['lat'], 'lng' => (float) $row['lng']],
            $rows,
        );
    }
}
```

(타임스탬프 설정은 `PhotoLocationModel` 과 동일하게 확정: `useTimestamps=true`,
`createdField='created_at'`, `updatedField=''` — 테이블에 updated_at 없음과 정합.)

- [ ] **Step 6: 통과 확인·정적 분석·커밋**

```bash
vendor/bin/phpunit tests/database/TimelinePointModelTest.php --no-coverage
composer analyse && composer test
git add app/Database/Migrations/2026-07-23-120000_CreateTimelinePoints.php app/Models/TimelinePointModel.php app/Services/Ingest/TimelinePoint.php tests/database/TimelinePointModelTest.php
git commit -m "✨ feat: 위치기록 timeline_points 테이블·모델 추가"
```

---

### Task 2: TimelineHistoryParser (TDD)

**Files:**
- Create: `app/Services/Ingest/TimelineHistoryParser.php`
- Create: `tests/_support/fixtures/timeline-sample.json`
- Test: `tests/unit/TimelineHistoryParserTest.php`

**Interfaces:**
- Consumes: Task 1 `TimelinePoint`
- Produces: `TimelineHistoryParser::parse(string $jsonPath): array` — `list<TimelinePoint>`(UTC 변환·불량 스킵), JSON 자체가 깨졌으면 `RuntimeException`

- [ ] **Step 1: fixture 작성** — `tests/_support/fixtures/timeline-sample.json`:

```json
{
  "semanticSegments": [
    {
      "startTime": "2026-07-20T09:00:00.000+09:00",
      "endTime": "2026-07-20T10:00:00.000+09:00",
      "timelinePath": [
        { "point": "37.5665000°, 126.9780000°", "time": "2026-07-20T09:10:00.000+09:00" },
        { "point": "37.5700000°, 126.9850000°", "time": "2026-07-20T09:20:00.000+09:00" },
        { "point": "이상한값", "time": "2026-07-20T09:25:00.000+09:00" },
        { "point": "37.5710000°, 126.9860000°" },
        { "point": "95.0000000°, 126.9860000°", "time": "2026-07-20T09:28:00.000+09:00" }
      ]
    },
    {
      "startTime": "2026-07-20T12:00:00.000+09:00",
      "endTime": "2026-07-20T13:00:00.000+09:00",
      "visit": { "topCandidate": { "placeLocation": { "latLng": "37.5°, 127.0°" } } }
    },
    {
      "startTime": "2026-07-20T15:00:00.000+09:00",
      "endTime": "2026-07-20T16:00:00.000+09:00",
      "timelinePath": [
        { "point": "37.5796000°, 126.9770000°", "time": "2026-07-20T15:30:00.000+09:00" }
      ]
    }
  ],
  "rawSignals": [],
  "userLocationProfile": {}
}
```

- [ ] **Step 2: 실패하는 테스트 작성** — `tests/unit/TimelineHistoryParserTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ingest\TimelineHistoryParser;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

/**
 * Timeline.json 파서 검증 — 도 기호 좌표·타임존 변환·불량 항목 스킵.
 */
final class TimelineHistoryParserTest extends CIUnitTestCase
{
    private function fixturePath(): string
    {
        return TESTPATH . '_support/fixtures/timeline-sample.json';
    }

    public function testParsesTimelinePathPointsAndConvertsToUtc(): void
    {
        $points = (new TimelineHistoryParser())->parse($this->fixturePath());

        // 유효 3건만: 불량 좌표·시각 누락·위도 범위 초과·visit-only 세그먼트는 제외
        $this->assertCount(3, $points);

        $this->assertSame(37.5665, $points[0]->lat);
        $this->assertSame(126.978, $points[0]->lng);
        // +09:00 → UTC 변환: 09:10 KST = 00:10 UTC
        $this->assertSame('2026-07-20 00:10:00', $points[0]->recordedAt);
        $this->assertSame('2026-07-20 00:20:00', $points[1]->recordedAt);
        $this->assertSame('2026-07-20 06:30:00', $points[2]->recordedAt);
    }

    public function testMissingSemanticSegmentsReturnsEmpty(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tl');
        file_put_contents($path, '{"rawSignals": []}');

        try {
            $this->assertSame([], (new TimelineHistoryParser())->parse($path));
        } finally {
            unlink($path);
        }
    }

    public function testInvalidJsonThrows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tl');
        file_put_contents($path, '{잘못된 json');

        try {
            $this->expectException(RuntimeException::class);
            (new TimelineHistoryParser())->parse($path);
        } finally {
            unlink($path);
        }
    }
}
```

- [ ] **Step 3: 실패 확인**

```bash
vendor/bin/phpunit tests/unit/TimelineHistoryParserTest.php --no-coverage
```

Expected: FAIL — TimelineHistoryParser not found

- [ ] **Step 4: 구현** — `app/Services/Ingest/TimelineHistoryParser.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Ingest;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * 기기 내보내기 Timeline.json 파서.
 *
 * semanticSegments[].timelinePath[] 만 사용한다(visit/activity 세그먼트는 MVP 제외).
 * point 는 "37.5665000°, 126.9780000°" 형식 — 도 기호를 제거해 float 로 변환하고,
 * time(오프셋 포함 ISO8601)은 UTC 'Y-m-d H:i:s' 로 변환한다(프로젝트 저장 표준).
 * 형식이 어긋난 항목은 조용히 건너뛴다.
 */
final class TimelineHistoryParser
{
    private const POINT_PATTERN = '/^\s*(-?\d+(?:\.\d+)?)\s*°?\s*,\s*(-?\d+(?:\.\d+)?)\s*°?\s*$/u';

    /**
     * @return list<TimelinePoint>
     */
    public function parse(string $jsonPath): array
    {
        $raw = file_get_contents($jsonPath);
        if ($raw === false) {
            throw new RuntimeException("위치기록 파일을 읽을 수 없습니다: {$jsonPath}");
        }

        try {
            /** @var array{semanticSegments?: list<array{timelinePath?: list<array{point?: string, time?: string}>}>} $data */
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('위치기록 JSON 형식이 올바르지 않습니다.', 0, $e);
        }

        $points = [];
        foreach ($data['semanticSegments'] ?? [] as $segment) {
            foreach ($segment['timelinePath'] ?? [] as $entry) {
                $point = $this->toPoint($entry);
                if ($point !== null) {
                    $points[] = $point;
                }
            }
        }

        return $points;
    }

    /**
     * timelinePath 항목 하나를 DTO 로 변환한다 — 불량이면 null.
     *
     * @param array{point?: string, time?: string} $entry
     */
    private function toPoint(array $entry): ?TimelinePoint
    {
        $pointRaw = $entry['point'] ?? null;
        $timeRaw = $entry['time'] ?? null;
        if (! is_string($pointRaw) || ! is_string($timeRaw)) {
            return null;
        }

        if (preg_match(self::POINT_PATTERN, $pointRaw, $m) !== 1) {
            return null;
        }
        $lat = (float) $m[1];
        $lng = (float) $m[2];
        if (abs($lat) > 90.0 || abs($lng) > 180.0) {
            return null;
        }

        try {
            $utc = (new DateTimeImmutable($timeRaw))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }

        return new TimelinePoint($lat, $lng, $utc);
    }
}
```

- [ ] **Step 5: 통과 확인·커밋**

```bash
vendor/bin/phpunit tests/unit/TimelineHistoryParserTest.php --no-coverage
composer analyse
git add app/Services/Ingest/TimelineHistoryParser.php tests/_support/fixtures/timeline-sample.json tests/unit/TimelineHistoryParserTest.php
git commit -m "✨ feat: Timeline.json 파서 추가 (timelinePath → UTC 포인트)"
```

---

### Task 3: TimelineTrackDownsampler (TDD)

**Files:**
- Create: `app/Services/Ingest/TimelineTrackDownsampler.php`
- Test: `tests/unit/TimelineTrackDownsamplerTest.php`

**Interfaces:**
- Consumes: `TimelinePoint`, 기존 `App\Services\GeoDistanceCalculator::kilometers()`
- Produces: `TimelineTrackDownsampler::downsample(array $points): array` — 시간순 정렬 후 "직전 유지점 대비 60초 미만 && 이동 10m 미만" 스킵, `list<TimelinePoint>`

- [ ] **Step 1: 실패하는 테스트** — `tests/unit/TimelineTrackDownsamplerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ingest\TimelinePoint;
use App\Services\Ingest\TimelineTrackDownsampler;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * 다운샘플링 검증 — 정지 구간 압축(60초 미만 && 10m 미만 스킵).
 */
final class TimelineTrackDownsamplerTest extends CIUnitTestCase
{
    public function testSkipsNearbyPointsWithinInterval(): void
    {
        // 같은 자리(이동 ≈0m)에서 30초 간격 3개 + 10분 뒤 먼 지점 1개
        $points = [
            new TimelinePoint(37.5665, 126.9780, '2026-07-20 00:00:00'),
            new TimelinePoint(37.56651, 126.97801, '2026-07-20 00:00:30'), // ~1.4m·30s → 스킵
            new TimelinePoint(37.56652, 126.97802, '2026-07-20 00:01:00'), // 유지점 대비 ~2.8m·60s → 유지(60초 경과)
            new TimelinePoint(37.5700, 126.9850, '2026-07-20 00:11:00'),
        ];

        $result = (new TimelineTrackDownsampler())->downsample($points);

        $this->assertCount(3, $result);
        $this->assertSame('2026-07-20 00:00:00', $result[0]->recordedAt);
        $this->assertSame('2026-07-20 00:01:00', $result[1]->recordedAt);
        $this->assertSame('2026-07-20 00:11:00', $result[2]->recordedAt);
    }

    public function testKeepsFastMovementWithinInterval(): void
    {
        // 30초 간격이지만 700m 이동 → 유지
        $points = [
            new TimelinePoint(37.5665, 126.9780, '2026-07-20 00:00:00'),
            new TimelinePoint(37.5728, 126.9780, '2026-07-20 00:00:30'),
        ];

        $this->assertCount(2, (new TimelineTrackDownsampler())->downsample($points));
    }

    public function testSortsByTimeBeforeSampling(): void
    {
        $points = [
            new TimelinePoint(37.5700, 126.9850, '2026-07-20 00:10:00'),
            new TimelinePoint(37.5665, 126.9780, '2026-07-20 00:00:00'),
        ];

        $result = (new TimelineTrackDownsampler())->downsample($points);

        $this->assertSame('2026-07-20 00:00:00', $result[0]->recordedAt);
        $this->assertSame('2026-07-20 00:10:00', $result[1]->recordedAt);
    }

    public function testEmptyInputReturnsEmpty(): void
    {
        $this->assertSame([], (new TimelineTrackDownsampler())->downsample([]));
    }
}
```

- [ ] **Step 2: 실패 확인** — `vendor/bin/phpunit tests/unit/TimelineTrackDownsamplerTest.php --no-coverage` → class not found

- [ ] **Step 3: 구현** — `app/Services/Ingest/TimelineTrackDownsampler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Ingest;

use App\Services\GeoDistanceCalculator;

/**
 * 위치기록 다운샘플러 — 정지 구간을 압축해 저장량·렌더 부담을 줄인다.
 *
 * 시간순 정렬 후, 직전 유지점 대비 60초 미만이면서 이동 10m 미만인 포인트를 건너뛴다.
 * 첫 포인트는 항상 유지한다.
 */
final class TimelineTrackDownsampler
{
    private const MIN_INTERVAL_SECONDS = 60;
    private const MIN_DISTANCE_METERS = 10.0;

    /**
     * @param list<TimelinePoint> $points
     *
     * @return list<TimelinePoint>
     */
    public function downsample(array $points): array
    {
        if ($points === []) {
            return [];
        }

        usort($points, static fn (TimelinePoint $a, TimelinePoint $b): int => $a->recordedAt <=> $b->recordedAt);

        $kept = [$points[0]];
        $last = $points[0];
        $count = count($points);

        for ($i = 1; $i < $count; $i++) {
            $current = $points[$i];
            // 같은 UTC 문자열 포맷끼리의 차이라 타임존과 무관하게 초 차이가 정확하다.
            $elapsed = strtotime($current->recordedAt) - strtotime($last->recordedAt);
            $meters = GeoDistanceCalculator::kilometers($last->lat, $last->lng, $current->lat, $current->lng) * 1000.0;

            if ($elapsed < self::MIN_INTERVAL_SECONDS && $meters < self::MIN_DISTANCE_METERS) {
                continue;
            }

            $kept[] = $current;
            $last = $current;
        }

        return $kept;
    }
}
```

- [ ] **Step 4: 통과·커밋**

```bash
vendor/bin/phpunit tests/unit/TimelineTrackDownsamplerTest.php --no-coverage
composer analyse
git add app/Services/Ingest/TimelineTrackDownsampler.php tests/unit/TimelineTrackDownsamplerTest.php
git commit -m "✨ feat: 위치기록 다운샘플러 추가 (정지 구간 압축)"
```

---

### Task 4: LocationHistoryService + Services 등록 (TDD)

**Files:**
- Create: `app/Services/LocationHistoryService.php`
- Modify: `app/Config/Services.php` (팩토리 1개 + use 문)
- Test: `tests/unit/LocationHistoryServiceTest.php`

**Interfaces:**
- Consumes: Task 1~3 전부, `App\Support\TimeConverter::kstDateToUtcRange`·`utcToKst`
- Produces:
  - `LocationHistoryService::__construct(TimelineHistoryParser $parser, TimelineTrackDownsampler $downsampler, TimelinePointModel $timelinePoints)`
  - `ingestFile(string $jsonPath, int $userId): array{saved: int, skipped: int, totalParsed: int, firstDate: ?string, lastDate: ?string}` — 64MB 초과 시 `RuntimeException`. firstDate/lastDate 는 KST 날짜(YYYY-MM-DD).
  - `trackForDate(int $userId, string $kstDate): list<array{0: float, 1: float}>` — `[[lat, lng], ...]`
  - `service('locationHistory')`

- [ ] **Step 1: 실패하는 테스트** — `tests/unit/LocationHistoryServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\TimelinePointModel;
use App\Services\Ingest\TimelineHistoryParser;
use App\Services\Ingest\TimelineTrackDownsampler;
use App\Services\LocationHistoryService;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

/**
 * 위치기록 인제스트·트랙 조회 조립 검증 — 모델만 Mock(파서는 final 이라 실제 인스턴스 사용).
 */
final class LocationHistoryServiceTest extends CIUnitTestCase
{
    // TimelineHistoryParser 는 final 이라 mock 할 수 없다 — 실제 파서 + 임시 fixture 로 검증한다.
    private function writeTempTimeline(string $json): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'tl');
        file_put_contents($path, $json);

        return $path;
    }

    public function testIngestFileParsesDownsamplesAndSaves(): void
    {
        // 두 번째 포인트는 20초·0m — 다운샘플링으로 스킵된다.
        $path = $this->writeTempTimeline(<<<'JSON'
            {"semanticSegments":[{"timelinePath":[
                {"point":"37.5665000°, 126.9780000°","time":"2026-07-20T09:10:00.000+09:00"},
                {"point":"37.5665000°, 126.9780000°","time":"2026-07-20T09:10:20.000+09:00"},
                {"point":"37.5700000°, 126.9850000°","time":"2026-07-21T15:00:00.000+09:00"}
            ]}]}
            JSON);

        $model = $this->createMock(TimelinePointModel::class);
        $model->method('saveBatch')->willReturnCallback(function (int $userId, array $points): int {
            $this->assertSame(7, $userId);
            $this->assertCount(2, $points); // 다운샘플링 후 2건
            $this->assertSame('2026-07-20 00:10:00', $points[0]->recordedAt); // +09:00 → UTC

            return 1; // 1건은 기존 중복이라 스킵됐다고 가정
        });

        $service = new LocationHistoryService(new TimelineHistoryParser(), new TimelineTrackDownsampler(), $model);

        try {
            $result = $service->ingestFile($path, 7);
        } finally {
            unlink($path);
        }

        $this->assertSame(1, $result['saved']);
        $this->assertSame(1, $result['skipped']); // 저장 대상 2 - 삽입 1
        $this->assertSame(3, $result['totalParsed']);
        $this->assertSame('2026-07-20', $result['firstDate']); // UTC 00:10 → KST 09:10 같은 날
        $this->assertSame('2026-07-21', $result['lastDate']);
    }

    public function testIngestFileRejectsOversizedFile(): void
    {
        $path = $this->writeTempTimeline('{}');

        $model = $this->createMock(TimelinePointModel::class);
        $model->expects($this->never())->method('saveBatch');

        // 상한 1바이트 — 파싱 전에 거부돼야 한다.
        $service = new LocationHistoryService(new TimelineHistoryParser(), new TimelineTrackDownsampler(), $model, 1);

        try {
            $this->expectException(RuntimeException::class);
            $service->ingestFile($path, 7);
        } finally {
            unlink($path);
        }
    }

    public function testTrackForDateConvertsKstDateAndReturnsPairs(): void
    {
        $model = $this->createMock(TimelinePointModel::class);
        $model->method('findTrackByUtcRange')->willReturnCallback(
            function (int $userId, string $from, string $to): array {
                $this->assertSame(7, $userId);
                $this->assertSame('2026-07-19 15:00:00', $from); // KST 2026-07-20 00:00 → UTC
                $this->assertSame('2026-07-20 14:59:59', $to);

                return [['lat' => 37.5665, 'lng' => 126.978]];
            },
        );

        // 파서는 final 이라 mock 불가 — 이 경로에선 호출되지 않으므로 실제 인스턴스를 넣는다.
        $service = new LocationHistoryService(
            new TimelineHistoryParser(),
            new TimelineTrackDownsampler(),
            $model,
        );

        $this->assertSame([[37.5665, 126.978]], $service->trackForDate(7, '2026-07-20'));
    }
}
```

- [ ] **Step 2: 실패 확인** — `vendor/bin/phpunit tests/unit/LocationHistoryServiceTest.php --no-coverage`

- [ ] **Step 3: 구현** — `app/Services/LocationHistoryService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TimelinePointModel;
use App\Services\Ingest\TimelineHistoryParser;
use App\Services\Ingest\TimelinePoint;
use App\Services\Ingest\TimelineTrackDownsampler;
use App\Support\TimeConverter;
use RuntimeException;

/**
 * 위치기록(Timeline.json) 유스케이스 — 업로드 인제스트와 날짜별 트랙 조회.
 */
final class LocationHistoryService
{
    private const DEFAULT_MAX_BYTES = 64 * 1024 * 1024;

    public function __construct(
        private readonly TimelineHistoryParser $parser,
        private readonly TimelineTrackDownsampler $downsampler,
        private readonly TimelinePointModel $timelinePoints,
        private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
    ) {
    }

    /**
     * 업로드된 Timeline.json 을 파싱·다운샘플링해 저장한다.
     *
     * @return array{saved: int, skipped: int, totalParsed: int, firstDate: ?string, lastDate: ?string}
     */
    public function ingestFile(string $jsonPath, int $userId): array
    {
        $size = filesize($jsonPath);
        if ($size === false || $size > $this->maxBytes) {
            throw new RuntimeException('위치기록 파일이 너무 큽니다(최대 64MB).');
        }

        $parsed = $this->parser->parse($jsonPath);
        $sampled = $this->downsampler->downsample($parsed);
        $saved = $this->timelinePoints->saveBatch($userId, $sampled);

        return [
            'saved' => $saved,
            'skipped' => count($sampled) - $saved,
            'totalParsed' => count($parsed),
            'firstDate' => $sampled === [] ? null : $this->kstDate($sampled[0]),
            'lastDate' => $sampled === [] ? null : $this->kstDate($sampled[count($sampled) - 1]),
        ];
    }

    /**
     * KST 날짜의 트랙 좌표쌍 목록을 돌려준다(지도 폴리라인용).
     *
     * @return list<array{0: float, 1: float}>
     */
    public function trackForDate(int $userId, string $kstDate): array
    {
        [$fromUtc, $toUtc] = TimeConverter::kstDateToUtcRange($kstDate);
        $rows = $this->timelinePoints->findTrackByUtcRange($userId, $fromUtc, $toUtc);

        return array_map(static fn (array $row): array => [$row['lat'], $row['lng']], $rows);
    }

    /**
     * 포인트의 KST 날짜(YYYY-MM-DD).
     */
    private function kstDate(TimelinePoint $point): string
    {
        return substr(TimeConverter::utcToKst($point->recordedAt), 0, 10);
    }
}
```

- [ ] **Step 4: Services 팩토리 추가** — `app/Config/Services.php` 끝에(use 문:
  `App\Services\LocationHistoryService`, `App\Services\Ingest\TimelineHistoryParser`,
  `App\Services\Ingest\TimelineTrackDownsampler`, `App\Models\TimelinePointModel` 알파벳순 삽입):

```php
    /**
     * 위치기록(Timeline.json) 인제스트·트랙 조회 서비스.
     */
    public static function locationHistory(bool $getShared = true): LocationHistoryService
    {
        if ($getShared) {
            return static::getSharedInstance('locationHistory');
        }

        return new LocationHistoryService(
            new TimelineHistoryParser(),
            new TimelineTrackDownsampler(),
            new TimelinePointModel(),
        );
    }
```

- [ ] **Step 5: 통과·커밋**

```bash
vendor/bin/phpunit tests/unit/LocationHistoryServiceTest.php --no-coverage
composer analyse && composer test
git add app/Services/LocationHistoryService.php app/Config/Services.php tests/unit/LocationHistoryServiceTest.php
git commit -m "✨ feat: 위치기록 인제스트·트랙 조회 LocationHistoryService 추가"
```

---

### Task 5: 컨트롤러·라우트·업로드 페이지 섹션

**Files:**
- Create: `app/Controllers/LocationHistoryController.php`
- Modify: `app/Config/Routes.php`, `app/Controllers/TakeoutController.php`(form 에 URL 1개 추가), `app/Views/upload.php`

**Interfaces:**
- Consumes: `service('locationHistory')`
- Produces: `POST /location-history/upload`(sessionRateLimit), `GET /location-history/track/(:segment)`

- [ ] **Step 1: 컨트롤러** — `app/Controllers/LocationHistoryController.php`
  (업로드 처리 순서는 `TakeoutController::handleZipUpload` 패턴을 그대로 따른다):

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * 위치기록(Timeline.json) — 업로드 인제스트와 날짜별 트랙 API.
 */
final class LocationHistoryController extends BaseController
{
    private const MAX_UPLOAD_BYTES = 64 * 1024 * 1024;

    /**
     * Timeline.json 업로드(POST /location-history/upload).
     */
    public function upload(): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => '로그인이 필요합니다.']);
        }

        $file = $this->request->getFile('file');
        if ($file === null) {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'Timeline.json 파일을 선택해주세요.']);
        }

        if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return $this->response->setStatusCode(413)->setJSON(['error' => '파일이 너무 큽니다. 서버 업로드 용량 제한을 초과했습니다.']);
        }

        if (strtolower($file->getClientExtension()) !== 'json') {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'json 파일만 업로드할 수 있습니다.']);
        }

        if ($file->getSize() === null || $file->getSize() > self::MAX_UPLOAD_BYTES) {
            return $this->response->setStatusCode(413)->setJSON(['error' => '파일이 너무 큽니다(최대 64MB).']);
        }

        try {
            $jsonPath = service('uploadedZipHandler')->store($file, WRITEPATH . 'uploads');
        } catch (Throwable $e) {
            log_message('error', '위치기록 업로드 실패: {msg}', ['msg' => $e->getMessage()]);

            return $this->response->setStatusCode(422)->setJSON(['error' => '업로드된 파일을 처리할 수 없습니다.']);
        }

        try {
            $result = service('locationHistory')->ingestFile($jsonPath, $userId);
        } catch (Throwable $e) {
            log_message('error', '위치기록 처리 실패: {msg}', ['msg' => $e->getMessage()]);

            return $this->response->setStatusCode(422)->setJSON(['error' => '위치기록 처리에 실패했습니다. 기기에서 내보낸 Timeline.json 인지 확인해주세요.']);
        } finally {
            if (is_file($jsonPath)) {
                unlink($jsonPath);
            }
        }

        return $this->response->setJSON([
            'saved' => $result['saved'],
            'skipped' => $result['skipped'],
            'total_parsed' => $result['totalParsed'],
            'first_date' => $result['firstDate'],
            'last_date' => $result['lastDate'],
        ]);
    }

    /**
     * 날짜별 트랙 좌표(GET /location-history/track/{date}).
     */
    public function track(string $date): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => '로그인이 필요합니다.']);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return $this->response->setStatusCode(422)->setJSON(['error' => '날짜 형식이 올바르지 않습니다(YYYY-MM-DD).']);
        }

        return $this->response->setJSON([
            'points' => service('locationHistory')->trackForDate($userId, $date),
        ]);
    }
}
```

- [ ] **Step 2: 라우트** — 기존 `photos/upload` 라우트 근처에:

```php
// 위치기록(Timeline.json) — 업로드는 무거운 동기 처리라 레이트리밋 적용
$routes->post('location-history/upload', 'LocationHistoryController::upload', ['filter' => 'sessionRateLimit']);
$routes->get('location-history/track/(:segment)', 'LocationHistoryController::track/$1');
```

- [ ] **Step 3: TakeoutController::form 에 URL 추가** — `view('upload', [...])` 배열에:

```php
            'locationHistoryUploadUrl' => site_url('location-history/upload'),
```

- [ ] **Step 4: upload.php 섹션 추가** — 기존 두 번째 `.upload-card`(일반 압축파일) 닫힌 뒤,
  같은 그리드 안에 세 번째 카드로 추가. 파일 상단 `@var` 독블록에 `$locationHistoryUploadUrl` 추가.
  기존 카드들의 폼 제출 JS 패턴을 먼저 읽고 동일한 UX(진행 표시·결과 표시)로 맞추되,
  아래 코드를 기준으로 한다:

```html
            <div class="upload-card">
                <span class="badge">위치기록</span>
                <h2>위치기록 Timeline.json</h2>
                <p class="lead-text">
                    휴대폰 <strong>설정 → 위치 → 타임라인</strong>에서 내보낸
                    <code>Timeline.json</code> 을 올리면 사진 사이 빈 구간의 이동 트랙을
                    지도에 점선으로 보여드립니다.
                </p>
                <p class="help">사진과 별개로 저장되며, 같은 파일을 다시 올려도 중복 저장되지 않습니다.</p>
                <form id="timeline-history-form" data-upload-url="<?= esc($locationHistoryUploadUrl, 'attr') ?>">
                    <input type="file" name="file" accept=".json,application/json" required>
                    <button type="submit" class="btn-block">위치기록 가져오기</button>
                    <p class="form-result" id="timeline-history-result" hidden></p>
                </form>
            </div>
```

JS(기존 인라인 스크립트 말미에 — 기존 폼 핸들러와 같은 방식이 있으면 그 헬퍼를 재사용하고,
없으면 아래 자체 리스너 사용):

```js
            (function () {
                var form = document.getElementById('timeline-history-form');
                var resultEl = document.getElementById('timeline-history-result');
                form.addEventListener('submit', function (evt) {
                    evt.preventDefault();
                    var btn = form.querySelector('button[type="submit"]');
                    btn.disabled = true;
                    btn.textContent = '처리 중...';
                    resultEl.hidden = true;

                    fetch(form.dataset.uploadUrl, { method: 'POST', body: new FormData(form) })
                        .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
                        .then(function (r) {
                            resultEl.hidden = false;
                            if (!r.ok) {
                                resultEl.textContent = r.data.error || '처리에 실패했습니다.';
                                return;
                            }
                            resultEl.textContent = '완료 — ' + r.data.saved + '개 지점 저장'
                                + (r.data.skipped ? ' (중복 ' + r.data.skipped + '개 건너뜀)' : '')
                                + (r.data.first_date ? ' · 기간 ' + r.data.first_date + ' ~ ' + r.data.last_date : '');
                        })
                        .catch(function () {
                            resultEl.hidden = false;
                            resultEl.textContent = '네트워크 오류가 발생했습니다.';
                        })
                        .then(function () {
                            btn.disabled = false;
                            btn.textContent = '위치기록 가져오기';
                        });
                });
            })();
```

`.form-result` CSS 가 없으면 upload.php `<style>` 에 추가: `.form-result { margin-top: 10px; font-size: 13px; color: #1a3e8e; }`

- [ ] **Step 5: 검증·커밋**

```bash
php -l app/Views/upload.php && composer analyse && composer test
git add app/Controllers/LocationHistoryController.php app/Config/Routes.php app/Controllers/TakeoutController.php app/Views/upload.php
git commit -m "✨ feat: 위치기록 업로드·트랙 API 와 업로드 페이지 섹션 추가"
```

---

### Task 6: 지도 트랙 토글·레이어 (map.php)

**Files:**
- Modify: `app/Views/map.php` — 사이드바 헤더 아래 토글, 인라인 JS 트랙 레이어, `RouteController::map` 이 넘기는 URL 1개

**Interfaces:**
- Consumes: `GET /location-history/track/{date}`(Task 5), 기존 `dateIndex`·`selectDay`·`preparePlayback`
- Produces: 없음(말단 UI)

- [ ] **Step 1: RouteController::map 에 URL 추가** — `view('map', [...])` 배열에:

```php
            'trackUrl' => site_url('location-history/track'),
```

map.php 의 `#map` div 에 데이터 속성 추가(기존 data-* 나란히):

```html
data-track-url="<?= esc($trackUrl, 'attr') ?>"
```

파일 상단 `@var` 독블록에 `$trackUrl` 항목 추가.

- [ ] **Step 2: 사이드바 토글 UI** — `#route-sidebar-header` div 바로 다음에:

```html
            <label id="track-toggle-row">
                <input type="checkbox" id="track-toggle"> 위치기록 트랙 표시
            </label>
```

CSS(`</style>` 직전):

```css
        #track-toggle-row {
            display: flex; align-items: center; gap: 6px; padding: 8px 16px;
            font-size: 12.5px; color: #555; border-bottom: 1px solid #eee; cursor: pointer;
        }
```

- [ ] **Step 3: 트랙 레이어 JS** — 재생(playback) 블록 아래에 추가:

```js
            // ── 위치기록 트랙(점선) ──────────────────────────
            var trackToggleEl = document.getElementById('track-toggle');
            var trackCache = {};      // 날짜 → [[lat,lng],...] (빈 배열 = 트랙 없음)
            var trackLayer = null;    // 현재 그려진 점선 폴리라인
            var selectedTrackDate = null; // 현재 선택된 날짜(토글 시 재사용)

            trackToggleEl.addEventListener('change', function () {
                refreshTrackLayer(selectedTrackDate);
            });

            // 날짜 선택·토글 변경 시 호출 — 기존 레이어를 정리하고 필요하면 다시 그린다.
            function refreshTrackLayer(date) {
                selectedTrackDate = date;
                if (trackLayer) { map.removeLayer(trackLayer); trackLayer = null; }
                if (!date || !trackToggleEl.checked) { return; }

                if (trackCache[date]) { drawTrack(date, trackCache[date]); return; }

                fetch(mapEl.dataset.trackUrl + '/' + date, { headers: { Accept: 'application/json' } })
                    .then(function (res) { return res.ok ? res.json() : { points: [] }; })
                    .then(function (data) {
                        trackCache[date] = data.points || [];
                        // 응답 대기 중 날짜·토글이 바뀌었으면 그리지 않는다.
                        if (selectedTrackDate === date && trackToggleEl.checked) {
                            drawTrack(date, trackCache[date]);
                        }
                    })
                    .catch(function () { /* 트랙은 보조 정보 — 실패는 조용히 무시 */ });
            }

            // 사진 동선(굵은 실선)과 구분되는 점선·얇은 선. 포인트 2개 미만이면 그리지 않는다.
            function drawTrack(date, points) {
                if (points.length < 2) { return; }
                var entry = dateIndex[date];
                var color = entry && entry.polyline ? entry.polyline.options.color : '#666';
                trackLayer = L.polyline(points, {
                    color: color, weight: 2, opacity: 0.7, dashArray: '4 6'
                }).addTo(map);
            }
```

- [ ] **Step 4: selectDay 연동** — `selectDay()` 마지막 `preparePlayback(itemEl.dataset.date);` 다음에:

```js
                refreshTrackLayer(itemEl.dataset.date);
```

시간표 버튼 분기(`preparePlayback(timelineDate);` 가 있는 곳) 다음에도:

```js
                    refreshTrackLayer(timelineDate);
```

- [ ] **Step 5: 검증·커밋**

```bash
php -l app/Views/map.php && composer analyse
git add app/Views/map.php app/Controllers/RouteController.php
git commit -m "✨ feat: 지도에 위치기록 트랙 토글·점선 레이어 추가"
```

---

### Task 7: 통합 검증 및 dev 머지 (컨트롤러 직접 수행)

- [ ] **Step 1:** `composer ci` 전체 통과 확인.
- [ ] **Step 2:** 브라우저 검증 — 임시 dev 라우트(canned): `/dev/map` 형태로 map 뷰 + canned routes + canned track JSON 을 서빙해 토글 ON/OFF·날짜 선택 시 점선 트랙 렌더·날짜 전환 시 레이어 정리·트랙 없는 날 무시를 확인. upload 페이지 섹션 렌더도 확인. 확인 후 Routes.php 원복(커밋 금지).
- [ ] **Step 3:** dev 머지:

```bash
git checkout dev && git pull origin dev
git merge --no-ff feature/location-history -m "🔀 merge: 위치기록(Timeline.json) 지원"
git push origin dev && git branch -d feature/location-history
```

- [ ] **Step 4:** 배포 안내 — **마이그레이션 포함 배포**: `php spark migrate` 를 별도 필수 단계로 안내(백필 없음).
