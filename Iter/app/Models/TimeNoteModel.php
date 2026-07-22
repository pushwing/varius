<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class TimeNoteModel extends Model
{
    protected $table = 'time_notes';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;

    /**
     * @var list<string>
     */
    protected $allowedFields = [
        'user_id',
        'note_date',
        'hour',
        'memo',
    ];

    /**
     * 시간대 메모를 저장한다(있으면 갱신). 메모가 비면 삭제한다.
     */
    public function upsertNote(int $userId, string $date, int $hour, string $memo): void
    {
        $existing = $this->where('user_id', $userId)
            ->where('note_date', $date)
            ->where('hour', $hour)
            ->first();

        if ($memo === '') {
            if ($existing !== null) {
                $this->delete((int) $existing['id']);
            }

            return;
        }

        if ($existing !== null) {
            $this->update((int) $existing['id'], ['memo' => $memo]);

            return;
        }

        $this->insert([
            'user_id' => $userId,
            'note_date' => $date,
            'hour' => $hour,
            'memo' => $memo,
        ]);
    }

    /**
     * 해당 날짜의 시간대별 메모를 반환한다.
     *
     * @return array<int, string> 시(0-23) → 메모
     */
    public function findForDate(int $userId, string $date): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->select('hour, memo')
            ->where('user_id', $userId)
            ->where('note_date', $date)
            ->orderBy('hour', 'ASC')
            ->findAll();

        $memos = [];
        foreach ($rows as $row) {
            $memos[(int) $row['hour']] = (string) $row['memo'];
        }

        return $memos;
    }
}
