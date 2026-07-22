<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 여행 그룹핑 — 연속된 날짜를 하나의 여행으로 묶는다. 멤버십은 별도 테이블 없이
 * [start_date, end_date] 범위로 계산한다(그 범위 안에 사진이 있는 날짜들).
 */
class CreateTrips extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'user_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'title' => ['type' => 'VARCHAR', 'constraint' => 100, 'default' => ''],
            'body' => ['type' => 'TEXT', 'null' => true],
            'start_date' => ['type' => 'DATE'],
            'end_date' => ['type' => 'DATE'],
            'cover_photo_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['user_id', 'start_date'], false, false, 'idx_trips_user_start');
        $this->forge->addForeignKey('user_id', 'users', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('cover_photo_id', 'photo_locations', 'id', '', 'SET NULL');
        $this->forge->createTable('trips');
    }

    public function down(): void
    {
        $this->forge->dropTable('trips');
    }
}
