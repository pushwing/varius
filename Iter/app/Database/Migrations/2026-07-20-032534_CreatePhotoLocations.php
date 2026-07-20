<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePhotoLocations extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'user_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'google_media_item_id' => ['type' => 'VARCHAR', 'constraint' => 255],
            'lat' => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true],
            'lng' => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true],
            'taken_at' => ['type' => 'DATETIME'],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        // 날짜별 동선 조회용 복합 인덱스.
        $this->forge->addKey(['user_id', 'taken_at'], false, false, 'idx_user_date');
        // 재적재 idempotency — 사용자당 같은 mediaItem 은 한 번만.
        $this->forge->addUniqueKey(['user_id', 'google_media_item_id'], 'uniq_photo_locations_user_media');
        $this->forge->addForeignKey('user_id', 'users', 'id', '', 'CASCADE');
        $this->forge->createTable('photo_locations', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('photo_locations', true);
    }
}
