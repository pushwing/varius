<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateRoles extends Migration
{
    public function up(): void
    {
        $this->forge->addField(['id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true], 'name' => ['type' => 'VARCHAR', 'constraint' => 50], 'created_at' => ['type' => 'TIMESTAMP', 'null' => true], 'updated_at' => ['type' => 'TIMESTAMP', 'null' => true]]);
        $this->forge->addKey('id', true); $this->forge->addUniqueKey('name'); $this->forge->createTable('roles');
    }

    public function down(): void { $this->forge->dropTable('roles', true); }
}
