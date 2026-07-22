<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class DayNoteModel extends Model
{
    protected $table = 'day_notes';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;

    /**
     * @var list<string>
     */
    protected $allowedFields = [
        'user_id',
        'note_date',
        'title',
        'body',
    ];

    /**
     * 날짜 노트를 저장한다(있으면 갱신). 제목·내용이 모두 비면 노트를 삭제한다.
     */
    public function upsertNote(int $userId, string $date, string $title, string $body): void
    {
        $existing = $this->where('user_id', $userId)->where('note_date', $date)->first();

        if ($title === '' && $body === '') {
            if ($existing !== null) {
                $this->delete((int) $existing['id']);
            }

            return;
        }

        if ($existing !== null) {
            $this->update((int) $existing['id'], ['title' => $title, 'body' => $body]);

            return;
        }

        $this->insert([
            'user_id' => $userId,
            'note_date' => $date,
            'title' => $title,
            'body' => $body,
        ]);
    }

    /**
     * 해당 날짜의 노트를 반환한다(없으면 null).
     *
     * @return array{title: string, body: string}|null
     */
    public function findForDate(int $userId, string $date): ?array
    {
        $row = $this->select('title, body')
            ->where('user_id', $userId)
            ->where('note_date', $date)
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'title' => (string) $row['title'],
            'body' => (string) ($row['body'] ?? ''),
        ];
    }
}
