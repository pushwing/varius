<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

final class RestaurantPhotoModel extends Model
{
    protected $table = 'restaurant_photos';
    protected $returnType = 'array';
    protected $allowedFields = ['restaurant_id', 'file_name', 'original_name', 'mime_type', 'size', 'is_hidden', 'sort_order'];
    protected $useTimestamps = true;
}
