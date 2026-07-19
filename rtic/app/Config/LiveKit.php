<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

class LiveKit extends BaseConfig
{
    public string $apiKey = '';

    public string $apiSecret = '';

    public string $url = '';

    public int $tokenTtl = 300;

    public string $roomName = 'rtic-home';
}
