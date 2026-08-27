<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

final class Geocoding extends BaseConfig
{
    public string $endpoint = 'https://nominatim.openstreetmap.org/search';
    public string $userAgent = 'Pagus/1.0 (+http://pagus.test/)';
    public int $timeout = 5;
    public int $connectTimeout = 3;
    public int $resultLimit = 5;
}
