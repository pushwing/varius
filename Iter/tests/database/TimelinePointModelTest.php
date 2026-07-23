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
 *
 * @internal
 */
final class TimelinePointModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $refresh = true;
    protected $namespace = 'App';

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
