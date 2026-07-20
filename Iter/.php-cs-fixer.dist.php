<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/app', __DIR__ . '/tests'])
    ->exclude(['Views'])
    ->notName('*.tpl.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12'                  => true,
        'declare_strict_types'    => true,
        'array_syntax'            => ['syntax' => 'short'],
        'no_unused_imports'       => true,
        'ordered_imports'         => ['sort_algorithm' => 'alpha'],
        'single_quote'            => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
        'binary_operator_spaces'  => ['default' => 'single_space'],
    ])
    ->setFinder($finder);
