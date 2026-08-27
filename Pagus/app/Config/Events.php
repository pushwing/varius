<?php

namespace Config;

use CodeIgniter\Events\Events;
use CodeIgniter\Exceptions\FrameworkException;
use CodeIgniter\HotReloader\HotReloader;

Events::on('pre_system', static function (): void {
    if (ENVIRONMENT !== 'testing') {
        $value = ini_get('zlib.output_compression');

        if (filter_var($value, FILTER_VALIDATE_BOOLEAN) || (int) $value > 0) {
            throw FrameworkException::forEnabledZlibOutputCompression();
        }

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        ob_start(static fn ($buffer) => $buffer);
    }

    if (CI_DEBUG && ! is_cli()) {
        Events::on('DBQuery', 'CodeIgniter\\Debug\\Toolbar\\Collectors\\Database::collect');
        service('toolbar')->respond();

        if (ENVIRONMENT === 'development') {
            service('routes')->get('__hot-reload', static function (): void {
                (new HotReloader())->run();
            });
        }
    }
});
