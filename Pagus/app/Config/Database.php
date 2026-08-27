<?php

namespace Config;

use CodeIgniter\Database\Config;

class Database extends Config
{
    public string $filesPath = APPPATH . 'Database' . DIRECTORY_SEPARATOR;

    public string $defaultGroup = 'default';

    public array $default = [
        'DSN' => '', 'hostname' => 'localhost', 'username' => '', 'password' => '',
        'database' => 'pagus', 'DBDriver' => 'MySQLi', 'DBPrefix' => '',
        'pConnect' => false, 'DBDebug' => true, 'charset' => 'utf8mb4',
        'DBCollat' => 'utf8mb4_unicode_ci', 'swapPre' => '', 'encrypt' => false,
        'compress' => false, 'strictOn' => false, 'failover' => [], 'port' => 3306,
    ];
}
