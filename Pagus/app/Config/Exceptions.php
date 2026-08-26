<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;
use CodeIgniter\Debug\ExceptionHandler;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use Throwable;

class Exceptions extends BaseConfig
{
    public bool $log = true;
    public array $ignoreCodes = [404];
    public string $errorViewPath = APPPATH . 'Views/errors';
    public array $sensitiveDataInTrace = [];

    public function handler(int $statusCode, Throwable $exception): ExceptionHandlerInterface
    {
        return new ExceptionHandler($this);
    }
}
