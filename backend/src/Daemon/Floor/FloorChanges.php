<?php

declare(strict_types=1);

namespace QueueWallboard\Daemon\Floor;

final readonly class FloorChanges
{
    /**
     * @param list<string> $agentExtensions
     * @param list<string> $queueNames
     */
    public function __construct(
        public array $agentExtensions,
        public array $queueNames,
        public bool $isExchangeConnectionChanged,
        public bool $isLiveStateReplaced,
    ) {}

    public function isEmpty(): bool
    {
        return $this->agentExtensions === []
            && $this->queueNames === []
            && !$this->isExchangeConnectionChanged
            && !$this->isLiveStateReplaced;
    }
}
