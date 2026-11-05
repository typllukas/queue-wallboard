<?php

declare(strict_types=1);

use QueueWallboard\Tools\Rector\StaticReturnInFinalClassRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withRules([StaticReturnInFinalClassRector::class]);
