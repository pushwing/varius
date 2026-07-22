<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 시간대 메모의 키를 시(hour, 0-23)에서 세그먼트 시각(slot, "HH:MM")으로 변경한다.
 *
 * 시간표가 시 단위 묶음이 아니라 장소 세그먼트 단위로 나뉘면서, 같은 시간대에도
 * 여러 행(장소)이 생기므로 메모 키가 분 단위 시각이어야 한다.
 */
class ChangeTimeNotesHourToSlot extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('time_notes', [
            'slot' => ['type' => 'VARCHAR', 'constraint' => 5, 'default' => '', 'after' => 'note_date'],
        ]);

        // 기존 시(hour) 키를 "HH:00" 슬롯으로 이관한다(소량 데이터, DB 종속 함수 회피).
        $rows = $this->db->table('time_notes')->select('id, hour')->get()->getResultArray();
        foreach ($rows as $row) {
            $this->db->table('time_notes')
                ->where('id', (int) $row['id'])
                ->update(['slot' => sprintf('%02d:00', (int) $row['hour'])]);
        }

        $this->forge->dropKey('time_notes', 'uniq_time_notes_user_date_hour', false);
        $this->forge->dropColumn('time_notes', 'hour');
        $this->forge->addKey(['user_id', 'note_date', 'slot'], false, true, 'uniq_time_notes_user_date_slot');
        $this->forge->processIndexes('time_notes');
    }

    public function down(): void
    {
        $this->forge->addColumn('time_notes', [
            'hour' => ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => true, 'default' => 0, 'after' => 'note_date'],
        ]);

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

        $this->forge->dropKey('time_notes', 'uniq_time_notes_user_date_slot', false);
        $this->forge->dropColumn('time_notes', 'slot');
        $this->forge->addKey(['user_id', 'note_date', 'hour'], false, true, 'uniq_time_notes_user_date_hour');
        $this->forge->processIndexes('time_notes');
    }
}
