<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

final class RestaurantReviewModel extends Model
{
    protected $table = 'restaurant_reviews';
    protected $returnType = 'array';
    protected $allowedFields = ['restaurant_id', 'nickname', 'rating', 'content', 'author_password_hash', 'is_hidden', 'report_count'];
    protected $useTimestamps = true;
}
