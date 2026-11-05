<?php

declare(strict_types=1);

namespace QueueWallboard\Daemon\Floor;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final class FloorState
{
    /** @var array<int|string, Queue> */
    private array $queues = [];

    /** @var array<int|string, Agent> */
    private array $agents = [];

    /** @var array<int|string, true> */
    private array $changedAgentExtensions = [];

    /** @var array<int|string, true> */
    private array $changedQueueNames = [];

    private bool $isExchangeConnected = false;
    private bool $isExchangeConnectionChanged = false;
    private bool $isLiveStateReplaced = false;
    private string $countersDate;

    /**
     * @param list<QueueSettings> $queueSettings
     */
    public function __construct(array $queueSettings, private readonly ClockInterface $clock)
    {
        foreach ($queueSettings as $settings) {
            $this->queues[$settings->name] = new Queue($settings, $this->clock->now());
        }
        $this->countersDate = $this->clock->now()->format('Y-m-d');
    }

    public function addAgent(Agent $agent): void
    {
        $this->resetCountersIfNewDay();
        $this->agents[$agent->extension] = $agent;
        $this->markAgentChanged($agent);
    }

    public function addWaitingCall(string $queueName, string $uniqueId, DateTimeImmutable $waitingSince): void
    {
        $this->resetCountersIfNewDay();
        $this->findQueue($queueName)?->addWaitingCall(new WaitingCall($uniqueId, $waitingSince));
        $this->markQueueChanged($queueName);
    }

    public function removeWaitingCall(string $queueName, string $uniqueId): void
    {
        $this->resetCountersIfNewDay();
        $this->findQueue($queueName)?->removeWaitingCall($uniqueId);
        $this->markQueueChanged($queueName);
    }

    public function recordAnswer(string $queueName, string $interface, int $holdSeconds): void
    {
        $this->resetCountersIfNewDay();
        $this->findQueue($queueName)?->recordAnswer($interface, $holdSeconds);
        $this->markQueueChanged($queueName);
    }

    /**
     * @param list<Agent> $agents
     * @param array<int|string, list<WaitingCall>> $waitingCallsByQueue
     * @param array<int|string, list<string>> $interfacesInCallByQueue
     */
    public function replaceLiveState(array $agents, array $waitingCallsByQueue, array $interfacesInCallByQueue): void
    {
        $this->resetCountersIfNewDay();
        $this->isLiveStateReplaced = true;
        $this->changedAgentExtensions = [];
        $this->agents = [];
        foreach ($agents as $agent) {
            $this->agents[$agent->extension] = $agent;
            $this->changedAgentExtensions[$agent->extension] = true;
        }
        foreach ($this->queues as $queue) {
            $queue->replaceLiveCalls(
                $waitingCallsByQueue[$queue->settings->name] ?? [],
                $interfacesInCallByQueue[$queue->settings->name] ?? [],
            );
            $this->changedQueueNames[$queue->settings->name] = true;
        }
    }

    public function recordCallEnd(string $interface): void
    {
        $this->resetCountersIfNewDay();
        foreach ($this->queues as $queue) {
            if ($queue->hasOngoingCall($interface)) {
                $queue->endOngoingCall($interface);
                $this->markQueueChanged($queue->settings->name);
            }
        }
        $agent = $this->findAgentByInterface($interface);
        if ($agent === null) {
            return;
        }
        $agent->recordCallEnd($this->clock->now());
        $this->markAgentChanged($agent);
    }

    public function recordAbandon(string $queueName, int $holdSeconds): void
    {
        $this->resetCountersIfNewDay();
        $this->findQueue($queueName)?->counters->recordAbandon($holdSeconds);
        $this->markQueueChanged($queueName);
    }

    public function updateExtensionStatus(string $interface, ExtensionStatus $status): void
    {
        $this->resetCountersIfNewDay();
        $agent = $this->findAgentByInterface($interface);
        if ($agent === null) {
            return;
        }
        $agent->updateExtensionStatus($status, $this->clock->now());
        $this->markAgentChanged($agent);
    }

    public function updatePause(string $interface, ?PauseReason $reason): void
    {
        $this->resetCountersIfNewDay();
        $agent = $this->findAgentByInterface($interface);
        if ($agent === null) {
            return;
        }
        $agent->updatePause($reason, $this->clock->now());
        $this->markAgentChanged($agent);
    }

    public function updateExchangeConnection(bool $isConnected): void
    {
        $this->resetCountersIfNewDay();
        if ($isConnected === $this->isExchangeConnected) {
            return;
        }
        $this->isExchangeConnected = $isConnected;
        $this->isExchangeConnectionChanged = true;
    }

    public function resetCountersIfNewDay(): void
    {
        $now = $this->clock->now();
        $today = $now->format('Y-m-d');
        if ($today === $this->countersDate) {
            return;
        }
        $this->countersDate = $today;
        foreach ($this->queues as $queue) {
            $queue->resetCounters($now->setTime(0, 0));
            $this->changedQueueNames[$queue->settings->name] = true;
        }
    }

    public function takeChanges(): FloorChanges
    {
        $changes = new FloorChanges(
            array_map(strval(...), array_keys($this->changedAgentExtensions)),
            array_map(strval(...), array_keys($this->changedQueueNames)),
            $this->isExchangeConnectionChanged,
            $this->isLiveStateReplaced,
        );
        $this->changedAgentExtensions = [];
        $this->changedQueueNames = [];
        $this->isExchangeConnectionChanged = false;
        $this->isLiveStateReplaced = false;

        return $changes;
    }

    public function findAgent(string $extension): ?Agent
    {
        return $this->agents[$extension] ?? null;
    }

    public function findQueue(string $name): ?Queue
    {
        return $this->queues[$name] ?? null;
    }

    /**
     * @return list<Agent>
     */
    public function listAgents(): array
    {
        return array_values($this->agents);
    }

    /**
     * @return list<Queue>
     */
    public function listQueues(): array
    {
        return array_values($this->queues);
    }

    public function isExchangeConnected(): bool
    {
        return $this->isExchangeConnected;
    }

    public function countAvailable(string $queueName): int
    {
        return count(array_filter(
            $this->listAgentsInQueue($queueName),
            static fn(Agent $agent): bool => $agent->isAvailable(),
        ));
    }

    public function countOnPause(string $queueName, PauseReason $reason): int
    {
        return count(array_filter(
            $this->listAgentsInQueue($queueName),
            static fn(Agent $agent): bool => $agent->pauseReason === $reason,
        ));
    }

    /**
     * @return list<Agent>
     */
    private function listAgentsInQueue(string $queueName): array
    {
        return array_values(array_filter(
            $this->agents,
            static fn(Agent $agent): bool => in_array($queueName, $agent->queueNames, true),
        ));
    }

    private function findAgentByInterface(string $interface): ?Agent
    {
        foreach ($this->agents as $agent) {
            if ($agent->interface === $interface) {
                return $agent;
            }
        }

        return null;
    }

    private function markQueueChanged(string $queueName): void
    {
        if ($this->findQueue($queueName) !== null) {
            $this->changedQueueNames[$queueName] = true;
        }
    }

    private function markAgentChanged(Agent $agent): void
    {
        $this->changedAgentExtensions[$agent->extension] = true;
        foreach ($agent->queueNames as $queueName) {
            $this->markQueueChanged($queueName);
        }
    }
}
