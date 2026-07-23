<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 위치기록(Timeline.json) 트랙 포인트 테이블.
 *
 * recorded_at 은 UTC(프로젝트 표준). (user_id, recorded_at) 유니크로 재업로드 idempotency 를
 * 보장하며, 이 유니크 인덱스가 날짜 범위 조회도 커버한다.
 */
final class CreateTimelinePoints extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'user_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'lat' => ['type' => 'DECIMAL', 'constraint' => '10,7'],
            'lng' => ['type' => 'DECIMAL', 'constraint' => '10,7'],
            'recorded_at' => ['type' => 'DATETIME'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['user_id', 'recorded_at'], 'uniq_timeline_points_user_time');
        $this->forge->addForeignKey('user_id', 'users', 'id', '', 'CASCADE');
        $this->forge->createTable('timeline_points', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('timeline_points', true);
    }
}
