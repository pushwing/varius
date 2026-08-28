<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

final class RestaurantReviewReportModel extends Model
{
    protected $table = 'restaurant_review_reports';
    protected $returnType = 'array';
    protected $allowedFields = ['review_id', 'reporter_hash', 'reason', 'created_at'];
    protected $useTimestamps = false;
}
