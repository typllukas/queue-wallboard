<?php

declare(strict_types=1);

namespace QueueWallboard\Simulator;

use InvalidArgumentException;

enum Scenario: string
{
    case Quiet = 'quiet';
    case Normal = 'normal';
    case Peak = 'peak';

    public function getCallsPerMinute(string $queueName): float
    {
        return match ($queueName) {
            Floor::SALES_QUEUE => match ($this) {
                self::Quiet => 1.5,
                self::Normal => 3.0,
                self::Peak => 3.5,
            },
            Floor::SUPPORT_QUEUE => match ($this) {
                self::Quiet => 1.0,
                self::Normal => 2.5,
                // more than the agents can answer, so support turns red within a minute
                self::Peak => 12.0,
            },
            default => throw new InvalidArgumentException(sprintf('No call rate for queue "%s".', $queueName)),
        };
    }
}
