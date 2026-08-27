<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

final class RestaurantModel extends Model
{
    protected $table = 'restaurants';
    protected $returnType = 'array';
    protected $allowedFields = ['name', 'address', 'latitude', 'longitude', 'phone', 'homepage_url', 'description', 'menu', 'business_hours', 'tags', 'is_published'];
    protected $useTimestamps = true;
}
