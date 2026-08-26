<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;
use CodeIgniter\Log\Handlers\FileHandler;

class Logger extends BaseConfig
{
    public $threshold = 9;
    public string $dateFormat = 'Y-m-d H:i:s';
    public array $handlers = [FileHandler::class => ['handles' => ['critical', 'alert', 'emergency', 'error', 'warning', 'notice', 'info', 'debug']]];
}
