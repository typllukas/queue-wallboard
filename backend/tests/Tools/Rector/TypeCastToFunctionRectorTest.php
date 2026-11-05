<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Tools\Rector;

use Override;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

final class TypeCastToFunctionRectorTest extends AbstractRectorTestCase
{
    #[Override]
    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/type_cast_to_function_config.php';
    }

    public function testReplacesNumberAndStringCastsAndLeavesABoolCast(): void
    {
        $this->doTestFile(__DIR__ . '/Fixture/type_cast_to_function.php.inc');
    }
}
