<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call('RoleAndAdminSeeder');
    }
}
