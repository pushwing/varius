<?php

namespace App\Services;

use App\Models\UserModel;

final class AdminAuthService
{
    public function __construct(private readonly ?UserModel $users = null)
    {
    }

    public function login(string $email, string $password): bool
    {
        $user = ($this->users ?? model(UserModel::class))->select('users.*, roles.name AS role_name')
            ->join('roles', 'roles.id = users.role_id')
            ->where(['users.email' => $email, 'users.is_active' => 1, 'roles.name' => 'admin'])
            ->first();

        if (! is_array($user) || ! password_verify($password, (string) $user['password_hash'])) {
            return false;
        }

        session()->regenerate(true);
        session()->set(['user_id' => $user['id'], 'is_admin' => true, 'user_name' => $user['name']]);
        return true;
    }

    public function logout(): void
    {
        session()->remove(['user_id', 'is_admin', 'user_name']);
        session()->regenerate(true);
    }
}
