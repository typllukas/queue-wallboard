<?php

declare(strict_types=1);

namespace QueueWallboard\Daemon\Ami;

use Psr\Clock\ClockInterface;
use QueueWallboard\Ami\AmiPacket;
use QueueWallboard\Daemon\Floor\ExtensionStatus;
use QueueWallboard\Daemon\Floor\FloorState;
use QueueWallboard\Daemon\Floor\PauseReason;

final readonly class AmiEventHandler
{
    public function __construct(
        private FloorState $floor,
        private ClockInterface $clock,
    ) {}

    public function handleEvent(AmiPacket $event): void
    {
        match ($event->findEventName()) {
            'QueueCallerJoin' => $this->floor->addWaitingCall(
                $event->getField('Queue'),
                $event->getField('Uniqueid'),
                $this->clock->now(),
            ),
            'QueueCallerLeave' => $this->floor->removeWaitingCall($event->getField('Queue'), $event->getField('Uniqueid')),
            'QueueCallerAbandon' => $this->floor->recordAbandon($event->getField('Queue'), $event->getIntegerField('HoldTime')),
            'AgentConnect' => $this->floor->recordAnswer(
                $event->getField('Queue'),
                $event->getField('Interface'),
                $event->getIntegerField('HoldTime'),
            ),
            'AgentComplete' => $this->floor->recordCallEnd($event->getField('Interface')),
            'QueueMemberStatus' => $this->floor->updateExtensionStatus(
                $event->getField('Interface'),
                ExtensionStatus::fromDeviceState($event->getIntegerField('Status')),
            ),
            'QueueMemberPause' => $this->floor->updatePause(
                $event->getField('Interface'),
                $event->getField('Paused') === '1' ? PauseReason::fromAmiReason($event->getField('PausedReason')) : null,
            ),
            default => null,
        };
    }
}
