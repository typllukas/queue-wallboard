<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Daemon\Ami;

use Override;
use Psr\Log\AbstractLogger;
use Stringable;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public private(set) array $records = [];

    /**
     * @param array<mixed> $context
     */
    #[Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => strval($message), 'context' => $context];
    }

    /**
     * @return list<array{level: mixed, message: string, context: array<mixed>}>
     */
    public function findRecords(string $message): array
    {
        return array_values(array_filter(
            $this->records,
            static fn(array $record): bool => $record['message'] === $message,
        ));
    }
}
