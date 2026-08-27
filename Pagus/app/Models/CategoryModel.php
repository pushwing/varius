<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

final class CategoryModel extends Model
{
    protected $table = 'categories';
    protected $returnType = 'array';
    protected $allowedFields = ['name', 'is_active'];
    protected $useTimestamps = true;
}
