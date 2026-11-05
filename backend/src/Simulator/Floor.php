<?php

declare(strict_types=1);

namespace QueueWallboard\Simulator;

final readonly class Floor
{
    public const string SALES_QUEUE = 'sales';
    public const string SUPPORT_QUEUE = 'support';

    /**
     * @param list<string> $queueNames
     * @param list<AgentProfile> $agentProfiles
     */
    public function __construct(
        public array $queueNames,
        public array $agentProfiles,
    ) {}

    public static function createDemoFloor(): self
    {
        $bothQueueNames = [self::SALES_QUEUE, self::SUPPORT_QUEUE];

        return new self($bothQueueNames, [
            new AgentProfile('201', 'Petr Svoboda', [self::SALES_QUEUE], true),
            new AgentProfile('202', 'Jana Nováková', [self::SALES_QUEUE], false),
            new AgentProfile('203', 'Lucie Dvořáková', $bothQueueNames, true),
            new AgentProfile('204', 'Tomáš Veselý', $bothQueueNames, false),
            new AgentProfile('205', 'Eva Procházková', [self::SUPPORT_QUEUE], false),
            new AgentProfile('206', 'Martin Kučera', [self::SUPPORT_QUEUE], false),
        ]);
    }
}
