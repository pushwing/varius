<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([__DIR__ . '/app', __DIR__ . '/tests', __DIR__ . '/public'])
    ->name('*.php')
    ->ignoreDotFiles(true);

return (new Config())
    ->setRules([
        '@PSR12' => true,
        'single_quote' => true,
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(false);
