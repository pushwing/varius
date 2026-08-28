<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

final class Groq extends BaseConfig
{
    public string $apiKey = '';
    public string $endpoint = 'https://api.groq.com/openai/v1/chat/completions';
    public string $model = 'llama-3.1-8b-instant';
    public int $timeout = 10;
    public int $connectTimeout = 3;
}
