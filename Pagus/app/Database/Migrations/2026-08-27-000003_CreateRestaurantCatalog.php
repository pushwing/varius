<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateRestaurantCatalog extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name' => ['type' => 'VARCHAR', 'constraint' => 100],
            'is_active' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'TIMESTAMP', 'null' => true],
            'updated_at' => ['type' => 'TIMESTAMP', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('categories');

        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name' => ['type' => 'VARCHAR', 'constraint' => 150],
            'address' => ['type' => 'VARCHAR', 'constraint' => 255],
            'latitude' => ['type' => 'DECIMAL', 'constraint' => '10,7'],
            'longitude' => ['type' => 'DECIMAL', 'constraint' => '10,7'],
            'phone' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'homepage_url' => ['type' => 'VARCHAR', 'constraint' => 2048, 'null' => true],
            'description' => ['type' => 'TEXT', 'null' => true],
            'menu' => ['type' => 'TEXT', 'null' => true],
            'business_hours' => ['type' => 'TEXT', 'null' => true],
            'tags' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'is_published' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_at' => ['type' => 'TIMESTAMP', 'null' => true],
            'updated_at' => ['type' => 'TIMESTAMP', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['is_published', 'name']);
        $this->forge->createTable('restaurants');

        $this->forge->addField([
            'restaurant_id' => ['type' => 'INT', 'unsigned' => true],
            'category_id' => ['type' => 'INT', 'unsigned' => true],
        ]);
        $this->forge->addKey(['restaurant_id', 'category_id'], true);
        $this->forge->addForeignKey('restaurant_id', 'restaurants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('category_id', 'categories', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('restaurant_categories');
    }

    public function down(): void
    {
        $this->forge->dropTable('restaurant_categories', true);
        $this->forge->dropTable('restaurants', true);
        $this->forge->dropTable('categories', true);
    }
}
