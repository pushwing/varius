<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateRestaurantReviews extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'restaurant_id' => ['type' => 'INT', 'unsigned' => true],
            'nickname' => ['type' => 'VARCHAR', 'constraint' => 50],
            'rating' => ['type' => 'TINYINT', 'constraint' => 1],
            'content' => ['type' => 'TEXT'],
            'is_hidden' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'report_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'created_at' => ['type' => 'TIMESTAMP', 'null' => true],
            'updated_at' => ['type' => 'TIMESTAMP', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['restaurant_id', 'is_hidden', 'created_at']);
        $this->forge->addForeignKey('restaurant_id', 'restaurants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('restaurant_reviews');

        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'review_id' => ['type' => 'INT', 'unsigned' => true],
            'reporter_hash' => ['type' => 'CHAR', 'constraint' => 64],
            'reason' => ['type' => 'VARCHAR', 'constraint' => 100],
            'created_at' => ['type' => 'TIMESTAMP', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['review_id', 'reporter_hash']);
        $this->forge->addKey(['reporter_hash', 'created_at']);
        $this->forge->addForeignKey('review_id', 'restaurant_reviews', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('restaurant_review_reports');
    }

    public function down(): void
    {
        $this->forge->dropTable('restaurant_review_reports', true);
        $this->forge->dropTable('restaurant_reviews', true);
    }
}
