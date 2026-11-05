<?php

declare(strict_types=1);

namespace QueueWallboard\Daemon\Floor;

final readonly class QueueSettings
{
    public function __construct(
        public string $name,
        public string $title,
        public int $thresholdCalls,
        public int $thresholdUsers,
        public int $thresholdTime,
    ) {}

    /**
     * @return list<self>
     */
    public static function createDemoSettings(): array
    {
        // demo values, a real floor sets its own
        return [
            new self(name: 'sales', title: 'Sales', thresholdCalls: 5, thresholdUsers: 1, thresholdTime: 60),
            new self(name: 'support', title: 'Support', thresholdCalls: 4, thresholdUsers: 1, thresholdTime: 90),
        ];
    }
}
