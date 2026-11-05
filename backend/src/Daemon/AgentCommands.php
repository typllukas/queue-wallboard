<?php

declare(strict_types=1);

namespace QueueWallboard\Daemon;

use QueueWallboard\Ami\AmiPacket;
use QueueWallboard\Daemon\Ami\AmiActionSender;
use QueueWallboard\Daemon\Floor\Agent;
use QueueWallboard\Daemon\Floor\PauseReason;
use React\Promise\PromiseInterface;

final readonly class AgentCommands
{
    public function __construct(private AmiActionSender $amiActionSender) {}

    /**
     * @return PromiseInterface<AmiPacket>
     */
    public function pauseAgent(string $extension, PauseReason $reason): PromiseInterface
    {
        return $this->sendQueuePause($extension, ['Paused' => 'true', 'Reason' => $reason->toAmiReason()]);
    }

    /**
     * @return PromiseInterface<AmiPacket>
     */
    public function unpauseAgent(string $extension): PromiseInterface
    {
        return $this->sendQueuePause($extension, ['Paused' => 'false']);
    }

    /**
     * @param array<string, string> $pauseFields
     * @return PromiseInterface<AmiPacket>
     */
    private function sendQueuePause(string $extension, array $pauseFields): PromiseInterface
    {
        return $this->amiActionSender->sendAction('QueuePause', ['Interface' => Agent::INTERFACE_PREFIX . $extension, ...$pauseFields]);
    }
}
