<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;

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
        // CREATE INDEX 표준 문법은 MySQL·SQLite 공통 — 테스트에서도 같은 DDL 이 실행되도록 분기하지 않는다.
        // raw 쿼리는 DBPrefix 가 자동 적용되지 않으므로 prefixTable() 로 테이블명을 만든다(테스트 그룹은 'db_' 프리픽스 사용).
        $db = $this->connection();
        $table = $db->prefixTable('photo_locations');
        $this->db->query("CREATE INDEX idx_photo_locations_user_country ON {$table} (user_id, country_code)");
    }

    public function down(): void
    {
        $db = $this->connection();
        // DROP INDEX 문법은 드라이버별로 다르다(MySQL 은 테이블 지정 필요).
        if ($db->DBDriver === 'SQLite3') {
            $this->db->query('DROP INDEX idx_photo_locations_user_country');
        } else {
            $table = $db->prefixTable('photo_locations');
            $this->db->query("ALTER TABLE {$table} DROP INDEX idx_photo_locations_user_country");
        }
        $this->forge->dropColumn('photo_locations', 'country_code');
        $this->forge->dropColumn('photo_locations', 'region_code');
    }

    /**
     * prefixTable()/DBDriver 를 제공하는 실제 커넥션 타입으로 좁힌다.
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
