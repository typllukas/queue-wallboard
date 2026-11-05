<?php

declare(strict_types=1);

namespace QueueWallboard\Simulator;

use DateTimeImmutable;

final class SimulatedAgent
{
    /** Asterisk's device state numbers, as in the Status field of QueueMemberStatus */
    private const int DEVICE_NOT_IN_USE = 1;
    private const int DEVICE_IN_USE = 2;
    private const int DEVICE_RINGING = 6;

    public private(set) ?SimulatedCaller $ringingCaller = null;
    public private(set) ?SimulatedCaller $connectedCaller = null;
    public private(set) string $channelUniqueId = '';
    public private(set) string $channelName = '';
    public private(set) bool $isInWrapUp = false;
    public private(set) bool $isPaused = false;
    public private(set) string $pausedReason = '';
    public private(set) int $callsTaken = 0;
    public private(set) ?DateTimeImmutable $lastCallEndedAt = null;
    public private(set) ?DateTimeImmutable $lastPausedAt = null;
    public private(set) DateTimeImmutable $freeSince;
    private ?int $pauseStepId = null;

    public function __construct(
        public readonly AgentProfile $profile,
        public readonly DateTimeImmutable $loggedInSince,
        private readonly SimulationScheduler $scheduler,
    ) {
        $this->freeSince = $loggedInSince;
    }

    public function isFree(): bool
    {
        return !$this->isPaused && $this->ringingCaller === null && $this->connectedCaller === null && !$this->isInWrapUp;
    }

    public function isMemberOf(string $queueName): bool
    {
        return in_array($queueName, $this->profile->queueNames, true);
    }

    public function getDeviceState(): int
    {
        if ($this->ringingCaller !== null) {
            return self::DEVICE_RINGING;
        }

        return $this->connectedCaller !== null ? self::DEVICE_IN_USE : self::DEVICE_NOT_IN_USE;
    }

    public function startRinging(SimulatedCaller $caller, string $channelUniqueId, string $channelName): void
    {
        $this->ringingCaller = $caller;
        $this->channelUniqueId = $channelUniqueId;
        $this->channelName = $channelName;
    }

    public function stopRinging(): void
    {
        $this->ringingCaller = null;
    }

    public function connect(SimulatedCaller $caller): void
    {
        $this->ringingCaller = null;
        $this->connectedCaller = $caller;
    }

    public function completeCall(DateTimeImmutable $endedAt): void
    {
        $this->connectedCaller = null;
        $this->callsTaken++;
        $this->lastCallEndedAt = $endedAt;
        $this->isInWrapUp = true;
    }

    public function finishWrapUp(DateTimeImmutable $freeSince): void
    {
        $this->isInWrapUp = false;
        $this->freeSince = $freeSince;
    }

    public function pause(string $reason, DateTimeImmutable $pausedAt): void
    {
        $this->isPaused = true;
        $this->pausedReason = $reason;
        $this->lastPausedAt = $pausedAt;
    }

    public function resume(): void
    {
        $this->isPaused = false;
        $this->pausedReason = '';
    }

    /**
     * @param callable(): void $changePause
     */
    public function schedulePauseChange(float $delaySeconds, callable $changePause): void
    {
        if ($this->pauseStepId !== null) {
            $this->scheduler->cancelStep($this->pauseStepId);
        }
        $this->pauseStepId = $this->scheduler->scheduleStep($delaySeconds, function () use ($changePause): void {
            $this->pauseStepId = null;
            $changePause();
        });
    }
}
