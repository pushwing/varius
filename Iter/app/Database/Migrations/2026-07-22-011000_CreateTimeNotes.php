<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 시간대별 메모 — 시간별 동선 페이지의 각 시간 라인에 붙는 한 줄 메모.
 */
class CreateTimeNotes extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'user_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'note_date' => ['type' => 'DATE'],
            'hour' => ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => true],
            'memo' => ['type' => 'VARCHAR', 'constraint' => 500, 'default' => ''],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['user_id', 'note_date', 'hour'], 'uniq_time_notes_user_date_hour');
        $this->forge->addForeignKey('user_id', 'users', 'id', '', 'CASCADE');
        $this->forge->createTable('time_notes');
    }

    public function down(): void
    {
        $this->forge->dropTable('time_notes');
    }
}
