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
        'slot',
        'memo',
    ];

    /**
     * 세그먼트 메모를 저장한다(있으면 갱신). 메모가 비면 삭제한다.
     *
     * @param string $slot 세그먼트 시작 시각("HH:MM")
     */
    public function upsertNote(int $userId, string $date, string $slot, string $memo): void
    {
        $existing = $this->where('user_id', $userId)
            ->where('note_date', $date)
            ->where('slot', $slot)
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
            'slot' => $slot,
            'memo' => $memo,
        ]);
    }

    /**
     * 해당 날짜의 세그먼트별 메모를 반환한다.
     *
     * @return array<string, string> 슬롯("HH:MM") → 메모
     */
    public function findForDate(int $userId, string $date): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->select('slot, memo')
            ->where('user_id', $userId)
            ->where('note_date', $date)
            ->orderBy('slot', 'ASC')
            ->findAll();

        $memos = [];
        foreach ($rows as $row) {
            $memos[(string) $row['slot']] = (string) $row['memo'];
        }

        return $memos;
    }
}
