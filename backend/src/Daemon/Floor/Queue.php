<?php

declare(strict_types=1);

namespace QueueWallboard\Daemon\Floor;

use DateTimeImmutable;

final class Queue
{
    public private(set) QueueCounters $counters;

    /** @var array<string, WaitingCall> */
    private array $waitingCalls = [];

    /** @var array<string, true> */
    private array $interfacesInCall = [];

    public function __construct(public readonly QueueSettings $settings, DateTimeImmutable $countedSince)
    {
        $this->counters = new QueueCounters($countedSince);
    }

    public function addWaitingCall(WaitingCall $waitingCall): void
    {
        $this->waitingCalls[$waitingCall->id] = $waitingCall;
    }

    public function removeWaitingCall(string $waitingCallId): void
    {
        unset($this->waitingCalls[$waitingCallId]);
    }

    /**
     * @return list<WaitingCall>
     */
    public function listWaitingCallsLongestFirst(): array
    {
        $waitingCalls = array_values($this->waitingCalls);
        usort($waitingCalls, static fn(WaitingCall $first, WaitingCall $second): int => $first->waitingSince <=> $second->waitingSince);

        return $waitingCalls;
    }

    public function recordAnswer(string $interface, int $holdSeconds): void
    {
        $this->counters->recordAnswer($holdSeconds);
        $this->interfacesInCall[$interface] = true;
    }

    public function endOngoingCall(string $interface): void
    {
        unset($this->interfacesInCall[$interface]);
    }

    /**
     * @param list<WaitingCall> $waitingCalls
     * @param list<string> $interfacesInCall
     */
    public function replaceLiveCalls(array $waitingCalls, array $interfacesInCall): void
    {
        $this->waitingCalls = [];
        foreach ($waitingCalls as $waitingCall) {
            $this->waitingCalls[$waitingCall->id] = $waitingCall;
        }
        $this->interfacesInCall = array_fill_keys($interfacesInCall, true);
    }

    public function hasOngoingCall(string $interface): bool
    {
        return isset($this->interfacesInCall[$interface]);
    }

    public function countOngoingCalls(): int
    {
        return count($this->interfacesInCall);
    }

    public function resetCounters(DateTimeImmutable $countedSince): void
    {
        $this->counters = new QueueCounters($countedSince);
    }
}
