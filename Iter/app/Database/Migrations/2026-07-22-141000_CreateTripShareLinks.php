<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 여행 단위 SNS 공유 링크 — 무작위 토큰으로 비로그인 열람을 허용한다.
 * 기존 share_links(날짜 단위)와 병렬 구조.
 */
class CreateTripShareLinks extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'trip_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'token' => ['type' => 'CHAR', 'constraint' => 32],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('token', 'uniq_trip_share_links_token');
        $this->forge->addUniqueKey('trip_id', 'uniq_trip_share_links_trip');
        $this->forge->addForeignKey('trip_id', 'trips', 'id', '', 'CASCADE');
        $this->forge->createTable('trip_share_links');
    }

    public function down(): void
    {
        $this->forge->dropTable('trip_share_links');
    }
}
