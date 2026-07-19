<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table            = 'users';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useTimestamps    = true;
    protected $allowedFields    = ['email', 'password_hash'];

    /**
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        /** @var array<string, mixed>|null $user */
        $user = $this->where('email', $email)->first();

        return $user;
    }
}
