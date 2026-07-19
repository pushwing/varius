<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Turn extends BaseConfig
{
    public string $secret = '';

    public int $credentialTtl = 300;

    public string $realm = 'rtic-home';
}
