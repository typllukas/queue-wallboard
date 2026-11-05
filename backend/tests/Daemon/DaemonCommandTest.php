<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Daemon;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QueueWallboard\Daemon\DaemonCommand;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DaemonCommand::class)]
final class DaemonCommandTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function provideMalformedExchangeAddresses(): array
    {
        return [
            'no port' => ['tcp://simulator'],
            'no scheme' => ['simulator:5038'],
            'another scheme' => ['http://simulator:5038'],
            'a path' => ['tcp://simulator:5038/manager'],
        ];
    }

    #[DataProvider('provideMalformedExchangeAddresses')]
    public function testAMalformedExchangeAddressStopsTheStart(string $exchangeAddress): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CommandTester(new DaemonCommand())->execute(['--exchange' => $exchangeAddress]);
    }
}
