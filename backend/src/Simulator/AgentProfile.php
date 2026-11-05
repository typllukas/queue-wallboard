<?php

declare(strict_types=1);

namespace QueueWallboard\Simulator;

final readonly class AgentProfile
{
    public string $interface;

    /**
     * @param list<string> $queueNames
     */
    public function __construct(
        public string $extension,
        public string $name,
        public array $queueNames,
        public bool $isPersonControlled,
    ) {
        $this->interface = 'PJSIP/' . $extension;
    }
}
