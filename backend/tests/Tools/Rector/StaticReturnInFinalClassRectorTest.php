<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Tools\Rector;

use Override;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

final class StaticReturnInFinalClassRectorTest extends AbstractRectorTestCase
{
    #[Override]
    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/static_return_in_final_class_config.php';
    }

    public function testAFinalClassReturnsSelfAndAnOpenOneKeepsStatic(): void
    {
        $this->doTestFile(__DIR__ . '/Fixture/static_return_in_final_class.php.inc');
    }
}
