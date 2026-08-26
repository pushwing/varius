<?php

namespace App\Models;

use CodeIgniter\Model;

final class UserModel extends Model
{
    protected $table = 'users';
    protected $returnType = 'array';
    protected $allowedFields = ['role_id', 'email', 'password_hash', 'name', 'is_active'];
    protected $useTimestamps = true;
}
