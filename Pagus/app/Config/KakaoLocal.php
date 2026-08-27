<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

final class KakaoLocal extends BaseConfig
{
    public string $apiKey = '';
    public string $endpoint = 'https://dapi.kakao.com/v2/local/search/keyword.json';
    public int $timeout = 5;
    public int $connectTimeout = 3;
    public int $resultLimit = 5;
}
