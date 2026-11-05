<?php

declare(strict_types=1);

use QueueWallboard\Tools\Rector\StaticReturnInFinalClassRector;
use QueueWallboard\Tools\Rector\TypeCastToFunctionRector;
use Rector\CodeQuality\Rector\If_\ExplicitNullableObjectInstanceRector;
use Rector\Config\RectorConfig;
use Rector\PHPUnit\CodeQuality\Rector\Class_\PreferPHPUnitThisCallRector;
use Rector\PHPUnit\CodeQuality\Rector\Class_\YieldDataProviderRector;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/tools'])
    ->withRootFiles()
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        phpunitCodeQuality: true,
    )
    ->withRules([StaticReturnInFinalClassRector::class, TypeCastToFunctionRector::class])
    ->withImportNames(removeUnusedImports: true)
    ->withSkip([
        // phpstan-strict-rules reports $this->assert*() as a dynamic call to a static method
        PreferPHPUnitThisCallRector::class,
        // a provider is a finite table, nothing about it is lazy
        YieldDataProviderRector::class,
        // keeps null checks as === null
        ExplicitNullableObjectInstanceRector::class,
    ]);
