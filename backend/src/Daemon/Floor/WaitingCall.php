<?php

declare(strict_types=1);

namespace QueueWallboard\Daemon\Floor;

use DateTimeImmutable;

final readonly class WaitingCall
{
    public function __construct(
        public string $id,
        public DateTimeImmutable $waitingSince,
    ) {}
}
