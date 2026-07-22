<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 날짜별 시간표(여행 스케줄) SNS 공유 링크 — 무작위 토큰으로 비로그인 열람을 허용한다.
 */
class CreateShareLinks extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'user_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'share_date' => ['type' => 'DATE'],
            'token' => ['type' => 'CHAR', 'constraint' => 32],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('token', 'uniq_share_links_token');
        $this->forge->addUniqueKey(['user_id', 'share_date'], 'uniq_share_links_user_date');
        $this->forge->addForeignKey('user_id', 'users', 'id', '', 'CASCADE');
        $this->forge->createTable('share_links');
    }

    public function down(): void
    {
        $this->forge->dropTable('share_links');
    }
}
