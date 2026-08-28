<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddReviewAuthorPassword extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('restaurant_reviews', [
            'author_password_hash' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'content'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('restaurant_reviews', 'author_password_hash');
    }
}
