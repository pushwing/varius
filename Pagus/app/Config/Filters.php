<?php

namespace Config;

use CodeIgniter\Config\Filters as BaseFilters;

class Filters extends BaseFilters
{
    public array $aliases = [
        'csrf' => \CodeIgniter\Filters\CSRF::class,
        'admin' => \App\Filters\AdminFilter::class,
    ];
    public array $globals = ['before' => ['csrf'], 'after' => []];
}
