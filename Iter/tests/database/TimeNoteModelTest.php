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

    public function testUpsertInsertsThenFindReturnsMemosByHour(): void
    {
        $model = new TimeNoteModel();
        $model->upsertNote($this->userId, '2024-03-15', 9, '경복궁 산책');
        $model->upsertNote($this->userId, '2024-03-15', 12, '점심 — 삼계탕');

        $memos = $model->findForDate($this->userId, '2024-03-15');

        $this->assertSame([9 => '경복궁 산책', 12 => '점심 — 삼계탕'], $memos);
    }

    public function testUpsertUpdatesExistingHourWithoutDuplicating(): void
    {
        $model = new TimeNoteModel();
        $model->upsertNote($this->userId, '2024-03-15', 9, '처음 메모');
        $model->upsertNote($this->userId, '2024-03-15', 9, '고친 메모');

        $memos = $model->findForDate($this->userId, '2024-03-15');

        $this->assertSame([9 => '고친 메모'], $memos);
        $this->assertSame(1, $model->where('user_id', $this->userId)->countAllResults());
    }

    public function testUpsertWithEmptyMemoDeletesNote(): void
    {
        $model = new TimeNoteModel();
        $model->upsertNote($this->userId, '2024-03-15', 9, '지울 메모');
        $model->upsertNote($this->userId, '2024-03-15', 9, '');

        $this->assertSame([], $model->findForDate($this->userId, '2024-03-15'));
    }

    public function testFindForDateScopesToUserAndDate(): void
    {
        $otherId = (new UserModel())->upsertByGoogleSub('sub-timenote-2', 'timenote2@example.com', 'TimeNote2');
        $model = new TimeNoteModel();
        $model->upsertNote($otherId, '2024-03-15', 9, '남의 메모');
        $model->upsertNote($this->userId, '2024-03-16', 9, '다른 날 메모');

        $this->assertSame([], $model->findForDate($this->userId, '2024-03-15'));
    }
}
