<?php

declare(strict_types=1);

// Layout only: no risky rules, so a fix never changes behaviour. Run `make cs-fix`; bin/verify checks changed files.
$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/config', __DIR__ . '/public', __DIR__ . '/bin'])
    ->append([__DIR__ . '/.php-cs-fixer.dist.php'])
    ->notPath([
        'bundles.php',
        'reference.php',
    ])
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS3.0' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const'], 'sort_algorithm' => 'alpha'],
        'fully_qualified_strict_types' => true,
        'no_extra_blank_lines' => true,
    ])
    ->setFinder($finder)
;
