<?php

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

use KepCustomFixer\CustomFixer\OrderedImportsGroupFixer;

$finder = Finder::create()
    ->in(__DIR__);

return (new Config())
    ->setRiskyAllowed(true)
    ->registerCustomFixers([new OrderedImportsGroupFixer()])
    ->setRules([
        '@PSR12' => true,
        'blank_line_after_namespace' => true,
        'blank_line_after_opening_tag' => true,
        'no_leading_import_slash' => true,
        'CustomFixer/ordered_imports_group' => [
            'sort_algorithm' => 'namespace',
            'imports_order' => [
                OrderedImportsGroupFixer::IMPORT_TYPE_CONST,
                OrderedImportsGroupFixer::IMPORT_TYPE_CLASS,
                OrderedImportsGroupFixer::IMPORT_TYPE_FUNCTION,
            ],
            'namespace_priority' => [
                'App\\' => 1,
                'DI\\' => -1
            ],
            //'no_namespace_priority' => 12,
        ],
    ])
    ->setFinder($finder);
