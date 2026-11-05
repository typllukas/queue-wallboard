<?php

declare(strict_types=1);

namespace QueueWallboard\Daemon\Floor;

use DateTimeImmutable;

final class Agent
{
    /** Asterisk identifies a queue member as technology/extension, e.g. PJSIP/203; only PJSIP phones are supported */
    public const string INTERFACE_PREFIX = 'PJSIP/';

    public readonly string $interface;

    /**
     * @param list<string> $queueNames
     */
    public function __construct(
        public readonly string $extension,
        public readonly string $title,
        public readonly array $queueNames,
        public readonly DateTimeImmutable $loggedInSince,
        public private(set) ExtensionStatus $extensionStatus,
        public private(set) ?PauseReason $pauseReason,
        public private(set) ?DateTimeImmutable $pausedSince,
        public private(set) ?DateTimeImmutable $lastCallAt,
        public private(set) DateTimeImmutable $stateSince,
    ) {
        $this->interface = self::INTERFACE_PREFIX . $extension;
    }

    public function isAvailable(): bool
    {
        return $this->pauseReason === null && $this->extensionStatus === ExtensionStatus::Idle;
    }

    public function getState(): AgentState
    {
        if ($this->pauseReason !== null) {
            return AgentState::Paused;
        }

        return $this->extensionStatus === ExtensionStatus::Busy ? AgentState::Session : AgentState::Idle;
    }

    public function updateExtensionStatus(ExtensionStatus $status, DateTimeImmutable $now): void
    {
        $stateBefore = $this->getState();
        $this->extensionStatus = $status;
        $this->restartStateClockIfChanged($stateBefore, $now);
    }

    public function updatePause(?PauseReason $reason, DateTimeImmutable $now): void
    {
        if ($reason === $this->pauseReason) {
            return;
        }
        $stateBefore = $this->getState();
        $this->pauseReason = $reason;
        $this->pausedSince = $reason === null ? null : $now;
        $this->restartStateClockIfChanged($stateBefore, $now);
    }

    public function recordCallEnd(DateTimeImmutable $now): void
    {
        $this->lastCallAt = $now;
    }

    private function restartStateClockIfChanged(AgentState $stateBefore, DateTimeImmutable $now): void
    {
        if ($this->getState() !== $stateBefore) {
            $this->stateSince = $now;
        }
    }
}
