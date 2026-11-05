<?php

declare(strict_types=1);

namespace QueueWallboard\Daemon;

use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Stringable;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Context values come from browsers and the PBX, and ConsoleLogger passes them to the console formatter. There a
 * line break fakes a log line, and a closing tag like </info> with no opening one throws and stops the daemon.
 */
final readonly class ConsoleEscapingLogger implements LoggerInterface
{
    use LoggerTrait;

    public function __construct(private LoggerInterface $consoleLogger) {}

    /**
     * @param array<mixed> $context
     */
    #[Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->consoleLogger->log($level, $message, array_map($this->escapeValue(...), $context));
    }

    private function escapeValue(mixed $value): mixed
    {
        if (!is_string($value) && !$value instanceof Stringable) {
            return $value;
        }

        return addcslashes(OutputFormatter::escape(strval($value)), "\0..\37");
    }
}
