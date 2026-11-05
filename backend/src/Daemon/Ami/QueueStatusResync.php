<?php

declare(strict_types=1);

namespace QueueWallboard\Daemon\Ami;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use QueueWallboard\Ami\AmiPacket;
use QueueWallboard\Daemon\Floor\Agent;
use QueueWallboard\Daemon\Floor\ExtensionStatus;
use QueueWallboard\Daemon\Floor\FloorState;
use QueueWallboard\Daemon\Floor\PauseReason;
use QueueWallboard\Daemon\Floor\WaitingCall;
use Throwable;
use UnexpectedValueException;

final class QueueStatusResync
{
    private ?string $listingActionId = null;

    /** @var array<string, list<AmiPacket>> */
    private array $memberItemsByLocation = [];

    /** @var array<int|string, list<WaitingCall>> */
    private array $waitingCallsByQueue = [];

    /** @var list<AmiPacket> */
    private array $bufferedEvents = [];

    public function __construct(
        private readonly FloorState $floor,
        private readonly AmiEventHandler $eventHandler,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {}

    public function begin(string $actionId): void
    {
        $this->forgetList();
        $this->listingActionId = $actionId;
    }

    public function abort(): void
    {
        $this->forgetList();
        $this->floor->updateExchangeConnection(false);
    }

    public function isListing(): bool
    {
        return $this->listingActionId !== null;
    }

    public function acceptPacket(AmiPacket $packet): bool
    {
        $eventName = $packet->findEventName();
        if ($this->listingActionId === null || $eventName === null) {
            return false;
        }
        if ($packet->findActionId() !== $this->listingActionId) {
            $this->bufferedEvents[] = $packet;

            return true;
        }
        // QueueParams is not read: its Completed and Abandoned count since the queue reset, not since midnight
        if ($eventName === 'QueueMember') {
            // one per agent per queue; Location in this reply, Interface in the events (Asterisk 22)
            $this->memberItemsByLocation[$packet->getField('Location')][] = $packet;
        }
        if ($eventName === 'QueueEntry') {
            $this->waitingCallsByQueue[$packet->getField('Queue')][] = new WaitingCall(
                $packet->getField('Uniqueid'),
                $this->clock->now()->modify(sprintf('-%d seconds', $packet->getIntegerField('Wait'))),
            );
        }
        if ($eventName === 'QueueStatusComplete') {
            $this->complete();
        }

        return true;
    }

    private function complete(): void
    {
        $agents = [];
        $interfacesInCallByQueue = [];
        foreach ($this->memberItemsByLocation as $location => $memberItems) {
            try {
                $agents[] = $this->createAgent($location, $memberItems);
            } catch (Throwable $throwable) {
                $this->logger->warning('Skipping QueueStatus member {location}: {error}', ['location' => $location, 'error' => $throwable->getMessage()]);

                continue;
            }
            foreach ($memberItems as $memberItem) {
                if ($memberItem->findField('InCall') === '1') {
                    $interfacesInCallByQueue[$memberItem->getField('Queue')][] = $location;
                }
            }
        }
        $waitingCallsByQueue = $this->waitingCallsByQueue;
        $bufferedEvents = $this->bufferedEvents;
        $this->forgetList();

        $this->floor->replaceLiveState($agents, $waitingCallsByQueue, $interfacesInCallByQueue);
        foreach ($bufferedEvents as $bufferedEvent) {
            try {
                $this->eventHandler->handleEvent($bufferedEvent);
            } catch (Throwable $throwable) {
                $this->logger->warning('Replaying an AMI event failed ({eventName}): {error}', [
                    'eventName' => $bufferedEvent->findEventName(),
                    'error' => $throwable->getMessage(),
                ]);
            }
        }
        $this->floor->updateExchangeConnection(true);
        $this->logger->info('Resync complete: {agentCount} agents, {waitingCallCount} waiting calls, {replayedEventCount} replayed events', [
            'agentCount' => count($agents),
            'waitingCallCount' => array_sum(array_map(count(...), $waitingCallsByQueue)),
            'replayedEventCount' => count($bufferedEvents),
        ]);
    }

    /**
     * @param list<AmiPacket> $memberItems
     */
    private function createAgent(string $location, array $memberItems): Agent
    {
        if (!str_starts_with($location, Agent::INTERFACE_PREFIX)) {
            throw new UnexpectedValueException(sprintf('Interface "%s" is not a %s interface.', $location, Agent::INTERFACE_PREFIX));
        }
        $memberItem = $memberItems[0];
        $now = $this->clock->now();
        $loggedInSince = $this->findTime($memberItem->getIntegerField('LoginTime')) ?? $now;
        $lastCallAt = $this->findTime($memberItem->getIntegerField('LastCall'));
        $lastPausedAt = $this->findTime($memberItem->getIntegerField('LastPause'));
        $pauseReason = $memberItem->getField('Paused') === '1' ? PauseReason::fromAmiReason($memberItem->getField('PausedReason')) : null;

        return new Agent(
            extension: substr($location, strlen(Agent::INTERFACE_PREFIX)),
            title: $memberItem->getField('Name'),
            queueNames: array_map(static fn(AmiPacket $queueMemberItem): string => $queueMemberItem->getField('Queue'), $memberItems),
            loggedInSince: $loggedInSince,
            extensionStatus: ExtensionStatus::fromDeviceState($memberItem->getIntegerField('Status')),
            pauseReason: $pauseReason,
            pausedSince: $pauseReason === null ? null : $lastPausedAt ?? $now,
            lastCallAt: $lastCallAt,
            stateSince: max([$loggedInSince, ...array_filter([$lastCallAt, $lastPausedAt])]),
        );
    }

    private function forgetList(): void
    {
        $this->listingActionId = null;
        $this->memberItemsByLocation = [];
        $this->waitingCallsByQueue = [];
        $this->bufferedEvents = [];
    }

    private function findTime(int $unixTimestamp): ?DateTimeImmutable
    {
        return $unixTimestamp === 0 ? null : $this->clock->now()->setTimestamp($unixTimestamp);
    }
}
