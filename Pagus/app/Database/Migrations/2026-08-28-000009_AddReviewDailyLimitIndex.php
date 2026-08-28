<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddReviewDailyLimitIndex extends Migration
{
    public function up(): void
    {
        $this->forge->addKey(['restaurant_id', 'author_reporter_hash', 'created_at'], false, false, 'restaurant_reviews_daily_author_idx');
        $this->forge->processIndexes('restaurant_reviews');
    }

    public function down(): void
    {
        $this->forge->dropKey('restaurant_reviews', 'restaurant_reviews_daily_author_idx');
    }
}
