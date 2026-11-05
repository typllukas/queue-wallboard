<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Daemon;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueueWallboard\Daemon\ConsoleEscapingLogger;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

#[CoversClass(ConsoleEscapingLogger::class)]
final class ConsoleEscapingLoggerTest extends TestCase
{
    public function testALineBreakInAValueCannotStartAForgedLogLine(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);

        new ConsoleEscapingLogger(new ConsoleLogger($output))->notice('Rejected: {error}', ['error' => "x\n[warning] AMI connection dropped"]);

        self::assertSame("[notice] Rejected: x\\n[warning] AMI connection dropped\n", $output->fetch());
    }

    public function testAStyleTagInAValueIsWrittenAsTextInsteadOfThrowing(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);

        new ConsoleEscapingLogger(new ConsoleLogger($output))->notice('Rejected: {error}', ['error' => 'Unknown action "</error>".']);

        self::assertSame("[notice] Rejected: Unknown action \"</error>\".\n", $output->fetch());
    }

    public function testValuesThatAreNotTextPassThrough(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);

        new ConsoleEscapingLogger(new ConsoleLogger($output))->notice('{count} open', ['count' => 3]);

        self::assertSame("[notice] 3 open\n", $output->fetch());
    }
}
