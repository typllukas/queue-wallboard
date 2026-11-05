<?php

declare(strict_types=1);

use QueueWallboard\Tools\Rector\TypeCastToFunctionRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withRules([TypeCastToFunctionRector::class]);
