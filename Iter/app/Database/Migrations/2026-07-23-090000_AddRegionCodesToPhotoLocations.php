<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 발자국 지도용 지역 코드 컬럼 추가.
 *
 * country_code: ISO 3166-1 alpha-2 (예: KR, JP). region_code: 국내 시·도 ISO 3166-2:KR (예: KR-11).
 * 판별 불가(바다·GPS 없음)는 null — 집계에서 제외된다.
 */
final class AddRegionCodesToPhotoLocations extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('photo_locations', [
            'country_code' => ['type' => 'VARCHAR', 'constraint' => 2, 'null' => true, 'after' => 'lng'],
            'region_code' => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => true, 'after' => 'country_code'],
        ]);
        // 인덱스는 사후 DDL로 생성 (운영 환경에서만 필요, 테스트 스킴은 기본 인덱스 전략 사용)
        if (! str_contains($this->db::class, 'SQLite')) {
            $this->db->query('CREATE INDEX idx_photo_locations_user_country ON photo_locations (user_id, country_code)');
        }
    }

    public function down(): void
    {
        if (! str_contains($this->db::class, 'SQLite')) {
            $this->db->query('ALTER TABLE photo_locations DROP INDEX idx_photo_locations_user_country');
        }
        $this->forge->dropColumn('photo_locations', 'country_code');
        $this->forge->dropColumn('photo_locations', 'region_code');
    }
}
