<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

final class RoleAndAdminSeeder extends Seeder
{
    public function run(): void
    {
        $role = $this->db->table('roles');
        $existingRole = $role->where('name', 'admin')->get()->getRowArray();
        $roleId = is_array($existingRole) ? (int) $existingRole['id'] : 0;
        if ($roleId === 0) {
            $role->insert(['name' => 'admin']);
            $roleId = (int) $this->db->insertID();
        }
        $password = getenv('PAGUS_ADMIN_PASSWORD');
        if (! is_string($password) || strlen($password) < 12) {
            throw new \RuntimeException('PAGUS_ADMIN_PASSWORD는 12자 이상이어야 합니다.');
        }
        $email = getenv('PAGUS_ADMIN_EMAIL') ?: 'admin@pagus.test';
        $existingUser = $this->db->table('users')->where('email', $email)->get()->getRowArray();
        if (! is_array($existingUser)) {
            $this->db->table('users')->insert(['role_id' => $roleId, 'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'name' => '파구스 운영자', 'is_active' => 1]);
        }
    }
}
