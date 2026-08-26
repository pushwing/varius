<?php

namespace App\Models;

use CodeIgniter\Model;

final class RoleModel extends Model
{
    protected $table = 'roles';
    protected $returnType = 'array';
    protected $allowedFields = ['name'];
}
