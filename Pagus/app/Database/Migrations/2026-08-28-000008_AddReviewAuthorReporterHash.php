<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddReviewAuthorReporterHash extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('restaurant_reviews', [
            'author_reporter_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true, 'after' => 'author_password_hash'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('restaurant_reviews', 'author_reporter_hash');
    }
}
