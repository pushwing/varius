<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\DayNoteModel;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class DayNoteModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $refresh = true;
    protected $namespace = 'App';

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        // day_notes.user_id 는 users FK 이므로 사용자를 먼저 시드한다.
        $this->userId = (new UserModel())->upsertByGoogleSub('sub-daynote', 'daynote@example.com', 'DayNote');
    }

    public function testUpsertInsertsThenFindReturnsNote(): void
    {
        $model = new DayNoteModel();
        $model->upsertNote($this->userId, '2024-03-15', '서울 여행 1일차', '경복궁과 북촌 한옥마을');

        $note = $model->findForDate($this->userId, '2024-03-15');

        $this->assertNotNull($note);
        $this->assertSame('서울 여행 1일차', $note['title']);
        $this->assertSame('경복궁과 북촌 한옥마을', $note['body']);
    }

    public function testUpsertUpdatesExistingNoteWithoutDuplicating(): void
    {
        $model = new DayNoteModel();
        $model->upsertNote($this->userId, '2024-03-15', '처음 제목', '처음 내용');
        $model->upsertNote($this->userId, '2024-03-15', '고친 제목', '고친 내용');

        $note = $model->findForDate($this->userId, '2024-03-15');

        $this->assertNotNull($note);
        $this->assertSame('고친 제목', $note['title']);
        $this->assertSame(1, $model->where('user_id', $this->userId)->countAllResults());
    }

    public function testUpsertWithEmptyTitleAndBodyDeletesNote(): void
    {
        $model = new DayNoteModel();
        $model->upsertNote($this->userId, '2024-03-15', '지울 제목', '지울 내용');
        $model->upsertNote($this->userId, '2024-03-15', '', '');

        $this->assertNull($model->findForDate($this->userId, '2024-03-15'));
    }

    public function testFindForDateDoesNotReturnOtherUsersNote(): void
    {
        $otherId = (new UserModel())->upsertByGoogleSub('sub-daynote-2', 'daynote2@example.com', 'DayNote2');
        $model = new DayNoteModel();
        $model->upsertNote($otherId, '2024-03-15', '남의 제목', '남의 내용');

        $this->assertNull($model->findForDate($this->userId, '2024-03-15'));
    }
}
