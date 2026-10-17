<?php

declare(strict_types=1);

// Layout rules, plus declare_strict_types, the one risky rule: every file already has it, so it only acts on a new file.
// Run `make cs-fix`; bin/verify checks changed files.
$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/config', __DIR__ . '/public', __DIR__ . '/bin'])
    ->append([__DIR__ . '/.php-cs-fixer.dist.php'])
    ->notPath([
        'bundles.php',
        'reference.php',
    ])
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        'declare_strict_types' => true,
        '@PER-CS3.0' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const'], 'sort_algorithm' => 'alpha'],
        'fully_qualified_strict_types' => true,
        'no_extra_blank_lines' => true,
    ])
    ->setFinder($finder)
;
