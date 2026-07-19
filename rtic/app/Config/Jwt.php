<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Jwt extends BaseConfig
{
    public string $secret = '';

    public string $algo = 'HS256';

    public int $roomEntryTtl = 300;

    public string $roomName = 'rtic-home';
}
