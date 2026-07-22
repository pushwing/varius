<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * 시간대 메모의 키를 시(hour, 0-23)에서 세그먼트 시각(slot, "HH:MM")으로 변경한다.
 *
 * 시간표가 시 단위 묶음이 아니라 장소 세그먼트 단위로 나뉘면서, 같은 시간대에도
 * 여러 행(장소)이 생기므로 메모 키가 분 단위 시각이어야 한다.
 *
 * 주의 — 인덱스 순서: MySQL 은 FK(user_id)를 받쳐줄 인덱스가 최소 하나 있어야
 * 기존 인덱스를 지울 수 있으므로, 새 유니크 인덱스를 먼저 만들고 옛것을 지운다.
 * 각 단계는 존재 여부를 확인해 부분 적용 상태에서 재실행해도 안전하다.
 */
class ChangeTimeNotesHourToSlot extends Migration
{
    public function up(): void
    {
        if (! $this->connection()->fieldExists('slot', 'time_notes')) {
            $this->forge->addColumn('time_notes', [
                'slot' => ['type' => 'VARCHAR', 'constraint' => 5, 'default' => '', 'after' => 'note_date'],
            ]);
        }

        // 기존 시(hour) 키를 "HH:00" 슬롯으로 이관한다(소량 데이터, DB 종속 함수 회피).
        if ($this->connection()->fieldExists('hour', 'time_notes')) {
            $rows = $this->db->table('time_notes')->select('id, hour')->where('slot', '')->get()->getResultArray();
            foreach ($rows as $row) {
                $this->db->table('time_notes')
                    ->where('id', (int) $row['id'])
                    ->update(['slot' => sprintf('%02d:00', (int) $row['hour'])]);
            }
        }

        // 새 유니크 인덱스를 먼저 생성(FK 지지 인덱스 확보) 후 옛 인덱스를 지운다.
        if (! $this->indexExists('uniq_time_notes_user_date_slot')) {
            $this->forge->addKey(['user_id', 'note_date', 'slot'], false, true, 'uniq_time_notes_user_date_slot');
            $this->forge->processIndexes('time_notes');
        }

        if ($this->indexExists('uniq_time_notes_user_date_hour')) {
            $this->forge->dropKey('time_notes', 'uniq_time_notes_user_date_hour', false);
        }

        if ($this->connection()->fieldExists('hour', 'time_notes')) {
            $this->forge->dropColumn('time_notes', 'hour');
        }
    }

    public function down(): void
    {
        if (! $this->connection()->fieldExists('hour', 'time_notes')) {
            $this->forge->addColumn('time_notes', [
                'hour' => ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => true, 'default' => 0, 'after' => 'note_date'],
            ]);
        }

        // 슬롯("HH:MM")을 시(hour)로 되돌린다. 같은 시로 합쳐지는 중복 슬롯은
        // 첫 행만 남긴다(시 단위 스키마의 유니크 제약 충족 — 롤백은 손실 변환).
        $rows = $this->db->table('time_notes')->select('id, user_id, note_date, slot')->get()->getResultArray();
        $seen = [];
        foreach ($rows as $row) {
            $hour = (int) substr((string) $row['slot'], 0, 2);
            $key = $row['user_id'] . '|' . $row['note_date'] . '|' . $hour;

            if (isset($seen[$key])) {
                $this->db->table('time_notes')->where('id', (int) $row['id'])->delete();
                continue;
            }

            $seen[$key] = true;
            $this->db->table('time_notes')->where('id', (int) $row['id'])->update(['hour' => $hour]);
        }

        // up() 과 동일한 이유로, 시 인덱스를 먼저 만들고 슬롯 인덱스를 지운다.
        if (! $this->indexExists('uniq_time_notes_user_date_hour')) {
            $this->forge->addKey(['user_id', 'note_date', 'hour'], false, true, 'uniq_time_notes_user_date_hour');
            $this->forge->processIndexes('time_notes');
        }

        if ($this->indexExists('uniq_time_notes_user_date_slot')) {
            $this->forge->dropKey('time_notes', 'uniq_time_notes_user_date_slot', false);
        }

        if ($this->connection()->fieldExists('slot', 'time_notes')) {
            $this->forge->dropColumn('time_notes', 'slot');
        }
    }

    /**
     * time_notes 테이블에 해당 이름의 인덱스가 있는지 확인한다.
     */
    private function indexExists(string $name): bool
    {
        foreach ($this->connection()->getIndexData('time_notes') as $index) {
            if ($index->name === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * fieldExists/getIndexData 를 제공하는 실제 커넥션 타입으로 좁힌다.
     *
     * @return BaseConnection<object|resource, object|resource>
     */
    private function connection(): BaseConnection
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('BaseConnection 이 아닌 커넥션에서는 실행할 수 없습니다.');
        }

        return $this->db;
    }
}
