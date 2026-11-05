<?php

declare(strict_types=1);

namespace QueueWallboard\Daemon\Wire;

use LogicException;
use QueueWallboard\Daemon\Floor\Agent;
use QueueWallboard\Daemon\Floor\FloorChanges;
use QueueWallboard\Daemon\Floor\FloorState;
use QueueWallboard\Daemon\Floor\PauseReason;
use QueueWallboard\Daemon\Floor\Queue;
use QueueWallboard\Daemon\Floor\QueueCounters;
use QueueWallboard\Daemon\Floor\WaitingCall;

final readonly class WireMapper
{
    /** an agent name from the exchange that is not valid UTF-8 must not fail the whole message */
    public const int JSON_ENCODE_FLAGS = JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION;

    public function __construct(private FloorState $floor) {}

    /**
     * @return array{
     *     id_agent: string,
     *     title: string,
     *     exten: string,
     *     state: string,
     *     exten_status: string,
     *     id_pause: string|null,
     *     onpause: string|null,
     *     statetime: string,
     *     logintime: string,
     *     lastcalltime: string|null,
     *     queues: list<string>,
     * }
     */
    private function mapAgent(Agent $agent): array
    {
        return [
            'id_agent' => $agent->extension,
            'title' => $agent->title,
            'exten' => $agent->extension,
            'state' => $agent->getState()->value,
            'exten_status' => $agent->extensionStatus->value,
            'id_pause' => $agent->pauseReason?->value,
            'onpause' => $agent->pausedSince?->format(DATE_ATOM),
            'statetime' => $agent->stateSince->format(DATE_ATOM),
            'logintime' => $agent->loggedInSince->format(DATE_ATOM),
            'lastcalltime' => $agent->lastCallAt?->format(DATE_ATOM),
            'queues' => $agent->queueNames,
        ];
    }

    /**
     * @return array{
     *     name: string,
     *     title: string,
     *     threshold_calls: int,
     *     threshold_users: int,
     *     threshold_time: int,
     *     ongoing: int,
     *     available: int,
     *     users_on_pause: array<string, int>,
     *     answered: int,
     *     unanswered: int,
     *     total: int,
     *     counted_since: string,
     *     abandon_rate: float|null,
     *     sla: float|null,
     *     svl: float|null,
     *     waiting_calls: list<array{id: string, waiting_since: string}>,
     * }
     */
    private function mapQueue(Queue $queue): array
    {
        $name = $queue->settings->name;
        $counters = $queue->counters;
        $usersOnPause = [];
        foreach (PauseReason::cases() as $reason) {
            $usersOnPause[$reason->value] = $this->floor->countOnPause($name, $reason);
        }

        return [
            'name' => $name,
            'title' => $queue->settings->title,
            'threshold_calls' => $queue->settings->thresholdCalls,
            'threshold_users' => $queue->settings->thresholdUsers,
            'threshold_time' => $queue->settings->thresholdTime,
            'ongoing' => $queue->countOngoingCalls(),
            'available' => $this->floor->countAvailable($name),
            'users_on_pause' => $usersOnPause,
            'answered' => $counters->countAnswered(),
            'unanswered' => $counters->countUnanswered(),
            'total' => $counters->countTotal(),
            'counted_since' => $counters->countedSince->format(DATE_ATOM),
            'abandon_rate' => $counters->calculateAbandonRate(),
            'sla' => $counters->calculateServiceLevel(),
            'svl' => $counters->calculateServiceAvailability(),
            'waiting_calls' => array_map(
                static fn(WaitingCall $waitingCall): array => [
                    'id' => $waitingCall->id,
                    'waiting_since' => $waitingCall->waitingSince->format(DATE_ATOM),
                ],
                $queue->listWaitingCallsLongestFirst(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mapSnapshot(): array
    {
        return [
            'action' => 'snapshot',
            'agents' => array_map($this->mapAgent(...), $this->floor->listAgents()),
            'queues' => array_map($this->mapQueue(...), $this->floor->listQueues()),
            'exchange_connected' => $this->floor->isExchangeConnected(),
            'sla_threshold_seconds' => QueueCounters::SLA_THRESHOLD_SECONDS,
        ];
    }

    /**
     * @return list<array{id_agent: string, ...<string, mixed>}>
     */
    public function mapChangedAgents(FloorChanges $changes): array
    {
        return array_map(
            fn(string $extension): array => $this->mapAgent(
                $this->floor->findAgent($extension) ?? throw new LogicException(sprintf('Changed agent %s is not on the floor.', $extension)),
            ),
            $changes->agentExtensions,
        );
    }

    /**
     * @return list<array{name: string, ...<string, mixed>}>
     */
    public function mapChangedQueues(FloorChanges $changes): array
    {
        return array_map(
            fn(string $queueName): array => $this->mapQueue(
                $this->floor->findQueue($queueName) ?? throw new LogicException(sprintf('Changed queue %s is not on the floor.', $queueName)),
            ),
            $changes->queueNames,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function mapFullPull(string $hash): array
    {
        return [
            ...$this->buildPull(
                $hash,
                true,
                array_map($this->mapAgent(...), $this->floor->listAgents()),
                array_map($this->mapQueue(...), $this->floor->listQueues()),
            ),
            'sla_threshold_seconds' => QueueCounters::SLA_THRESHOLD_SECONDS,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mapChangedPull(string $hash, FloorChanges $changes): array
    {
        return $this->buildPull($hash, false, $this->mapChangedAgents($changes), $this->mapChangedQueues($changes));
    }

    /**
     * @param list<array<string, mixed>> $agents
     * @param list<array<string, mixed>> $queues
     *
     * @return array<string, mixed>
     */
    private function buildPull(string $hash, bool $isFull, array $agents, array $queues): array
    {
        return [
            'hash' => $hash,
            'full' => $isFull,
            'agents' => $agents,
            'queues' => $queues,
            'exchange_connected' => $this->floor->isExchangeConnected(),
        ];
    }
}
