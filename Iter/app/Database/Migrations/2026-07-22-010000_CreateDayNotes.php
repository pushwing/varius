<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 날짜별 여행 노트(제목·내용) — 시간별 동선 페이지의 "여행 스케줄" 헤더용.
 */
class CreateDayNotes extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'user_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'note_date' => ['type' => 'DATE'],
            'title' => ['type' => 'VARCHAR', 'constraint' => 100, 'default' => ''],
            'body' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['user_id', 'note_date'], 'uniq_day_notes_user_date');
        $this->forge->addForeignKey('user_id', 'users', 'id', '', 'CASCADE');
        $this->forge->createTable('day_notes');
    }

    public function down(): void
    {
        $this->forge->dropTable('day_notes');
    }
}
