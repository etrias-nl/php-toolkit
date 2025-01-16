<?php

// @see https://mlocati.github.io/php-cs-fixer-configurator/

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PhpCsFixer' => true,
        '@PhpCsFixer:risky' => true,
        '@PHP81Migration' => true,
        '@PHP80Migration:risky' => true,
        '@PHPUnit100Migration:risky' => true,
        'header_comment' => ['header' => ''],
        'php_unit_test_case_static_method_calls' => ['call_type' => 'self'],
        'php_unit_test_class_requires_covers' => false,
        'no_superfluous_phpdoc_tags' => ['remove_inheritdoc' => true],
        'global_namespace_import' => ['import_classes' => false, 'import_constants' => false, 'import_functions' => false],
        'nullable_type_declaration_for_default_null_value' => true,
        'phpdoc_to_comment' => ['ignored_tags' => ['todo', 'psalm-suppress']],
        'comment_to_phpdoc' => ['ignored_tags' => ['todo']],
    ])
    ->setCacheFile(__DIR__.'/var/phpcsfixer-cache')
    ->setFinder(
        Finder::create()
            ->in([
                __DIR__.'/{src,tests}',
            ])
            ->append([
                __FILE__,
                __DIR__.'/rector.php',
            ])
            ->notPath([
                'Fixtures/Messenger/envelope_compressed.php',
                'Fixtures/Messenger/envelope_uncompressed.php',
            ])
    );
