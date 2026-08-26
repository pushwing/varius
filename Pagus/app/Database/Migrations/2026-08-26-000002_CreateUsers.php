<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateUsers extends Migration
{
    public function up(): void
    {
        $this->forge->addField(['id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true], 'role_id' => ['type' => 'INT', 'unsigned' => true], 'email' => ['type' => 'VARCHAR', 'constraint' => 254], 'password_hash' => ['type' => 'VARCHAR', 'constraint' => 255], 'name' => ['type' => 'VARCHAR', 'constraint' => 100], 'is_active' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1], 'created_at' => ['type' => 'TIMESTAMP', 'null' => true], 'updated_at' => ['type' => 'TIMESTAMP', 'null' => true]]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('email');
        $this->forge->addKey('role_id');
        $this->forge->addForeignKey('role_id', 'roles', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->createTable('users');
    }

    public function down(): void
    {
        $this->forge->dropTable('users', true);
    }
}
