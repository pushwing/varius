<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\TimeNoteModel;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class TimeNoteModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $refresh = true;
    protected $namespace = 'App';

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        // time_notes.user_id 는 users FK 이므로 사용자를 먼저 시드한다.
        $this->userId = (new UserModel())->upsertByGoogleSub('sub-timenote', 'timenote@example.com', 'TimeNote');
    }

    public function testUpsertInsertsThenFindReturnsMemosBySlot(): void
    {
        $model = new TimeNoteModel();
        $model->upsertNote($this->userId, '2024-03-15', '09:10', '경복궁 산책');
        $model->upsertNote($this->userId, '2024-03-15', '12:05', '점심 — 삼계탕');

        $memos = $model->findForDate($this->userId, '2024-03-15');

        $this->assertSame(['09:10' => '경복궁 산책', '12:05' => '점심 — 삼계탕'], $memos);
    }

    public function testUpsertUpdatesExistingSlotWithoutDuplicating(): void
    {
        $model = new TimeNoteModel();
        $model->upsertNote($this->userId, '2024-03-15', '09:10', '처음 메모');
        $model->upsertNote($this->userId, '2024-03-15', '09:10', '고친 메모');

        $memos = $model->findForDate($this->userId, '2024-03-15');

        $this->assertSame(['09:10' => '고친 메모'], $memos);
        $this->assertSame(1, $model->where('user_id', $this->userId)->countAllResults());
    }

    public function testSameHourDifferentSlotsAreSeparateNotes(): void
    {
        // 같은 11시대라도 장소 세그먼트(시각)가 다르면 메모는 따로 저장된다.
        $model = new TimeNoteModel();
        $model->upsertNote($this->userId, '2024-03-15', '11:05', '카페');
        $model->upsertNote($this->userId, '2024-03-15', '11:40', '서점');

        $memos = $model->findForDate($this->userId, '2024-03-15');

        $this->assertSame(['11:05' => '카페', '11:40' => '서점'], $memos);
    }

    public function testUpsertWithEmptyMemoDeletesNote(): void
    {
        $model = new TimeNoteModel();
        $model->upsertNote($this->userId, '2024-03-15', '09:10', '지울 메모');
        $model->upsertNote($this->userId, '2024-03-15', '09:10', '');

        $this->assertSame([], $model->findForDate($this->userId, '2024-03-15'));
    }

    public function testFindForDateScopesToUserAndDate(): void
    {
        $otherId = (new UserModel())->upsertByGoogleSub('sub-timenote-2', 'timenote2@example.com', 'TimeNote2');
        $model = new TimeNoteModel();
        $model->upsertNote($otherId, '2024-03-15', '09:10', '남의 메모');
        $model->upsertNote($this->userId, '2024-03-16', '09:10', '다른 날 메모');

        $this->assertSame([], $model->findForDate($this->userId, '2024-03-15'));
    }
}
