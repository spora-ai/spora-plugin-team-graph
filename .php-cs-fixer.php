<?php

declare(strict_types=1);

// `routes/` is included because routes/team-graph.php is real PHP on the
// request surface — it declares TeamGraphRoutes and pins the route path
// plus its middleware — and phpstan.neon already analyses it. Leaving it
// out meant the only linted surface stopped before the route table.
$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/routes']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0'                        => true,
        'declare_strict_types'              => true,
        'fully_qualified_strict_types'      => true,
        'global_namespace_import'           => [
            'import_classes'    => true,
            'import_functions'  => false,
            'import_constants'  => false,
        ],
        'ordered_imports'                   => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'                 => true,
        'single_import_per_statement'       => true,
    ])
    ->setFinder($finder);
