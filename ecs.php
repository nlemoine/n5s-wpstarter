<?php

declare(strict_types=1);

use PhpCsFixer\Fixer\ClassNotation\FinalClassFixer;
use PhpCsFixer\Fixer\ClassNotation\OrderedClassElementsFixer;
use PhpCsFixer\Fixer\Strict\DeclareStrictTypesFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withRootFiles()
    ->withRules([
        DeclareStrictTypesFixer::class,
        FinalClassFixer::class,
        OrderedClassElementsFixer::class,
    ])
    ->withPreparedSets(
        psr12: true,
        arrays: true,
        spaces: true,
        namespaces: true,
        docblocks: true,
        controlStructures: true,
        comments: true,
        casing: true,
        cleanup: true,
    );
