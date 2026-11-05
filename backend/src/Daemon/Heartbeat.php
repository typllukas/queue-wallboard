<?php

declare(strict_types=1);

namespace QueueWallboard\Daemon;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use QueueWallboard\Daemon\Ami\AmiActionFailed;
use QueueWallboard\Daemon\Ami\AmiActionTimedOut;
use QueueWallboard\Daemon\Ami\AmiConnectionLost;
use QueueWallboard\Daemon\Floor\FloorState;
use QueueWallboard\Daemon\Floor\PauseReason;
use QueueWallboard\Daemon\WebSocket\ClientConnection;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

final class Heartbeat
{
    /** @var array<int, ClientConnection> */
    private array $connections = [];

    /** @var array<int|string, TimerInterface> */
    private array $inactivePauseTimers = [];

    private ?TimerInterface $pingTimer = null;

    public function __construct(
        private readonly LoopInterface $loop,
        private readonly DaemonSettings $settings,
        private readonly ClockInterface $clock,
        private readonly FloorState $floor,
        private readonly AgentCommands $agentCommands,
        private readonly LoggerInterface $logger,
    ) {}

    public function watchConnection(ClientConnection $connection): void
    {
        $this->connections[spl_object_id($connection)] = $connection;
        $this->pingTimer ??= $this->loop->addPeriodicTimer($this->settings->pingIntervalSeconds, $this->pingConnections(...));
        $extension = $connection->agentExtension;
        if ($extension !== null && isset($this->inactivePauseTimers[$extension])) {
            $this->loop->cancelTimer($this->inactivePauseTimers[$extension]);
            unset($this->inactivePauseTimers[$extension]);
        }
    }

    public function forgetConnection(ClientConnection $connection): void
    {
        if (!isset($this->connections[spl_object_id($connection)])) {
            return;
        }
        $this->removeConnection($connection);
        $extension = $connection->agentExtension;
        if ($extension === null || $this->hasConnectionOfAgent($extension)) {
            return;
        }
        $this->inactivePauseTimers[$extension] = $this->loop->addTimer(
            $this->settings->agentGraceSeconds,
            fn() => $this->pauseAgentWithoutPage($extension),
        );
    }

    public function stop(): void
    {
        foreach ([$this->pingTimer, ...$this->inactivePauseTimers] as $timer) {
            if ($timer !== null) {
                $this->loop->cancelTimer($timer);
            }
        }
        $this->pingTimer = null;
        $this->inactivePauseTimers = [];
        $this->connections = [];
    }

    private function pingConnections(): void
    {
        $silenceLimit = $this->clock->now()->modify(sprintf('-%d milliseconds', intval(round($this->settings->pongTimeoutSeconds * 1000))));
        foreach ($this->connections as $connection) {
            if (!$connection->isSilentSince($silenceLimit)) {
                $connection->sendMessage(['action' => 'ping']);

                continue;
            }
            $extension = $connection->agentExtension;
            $this->logger->info('Closing a WebSocket connection silent for {pongTimeoutSeconds} s (agent {extension})', [
                'pongTimeoutSeconds' => $this->settings->pongTimeoutSeconds,
                'extension' => $extension ?? 'none',
            ]);
            $this->removeConnection($connection);
            $connection->close();
            if ($extension !== null && !$this->hasConnectionOfAgent($extension)) {
                $this->pauseAgentWithoutPage($extension);
            }
        }
    }

    private function removeConnection(ClientConnection $connection): void
    {
        unset($this->connections[spl_object_id($connection)]);
        if ($this->connections === [] && $this->pingTimer !== null) {
            $this->loop->cancelTimer($this->pingTimer);
            $this->pingTimer = null;
        }
    }

    private function pauseAgentWithoutPage(string $extension): void
    {
        unset($this->inactivePauseTimers[$extension]);
        if (!$this->isKnownAgentUnpaused($extension)) {
            return;
        }
        $this->logger->notice('Agent {extension} has no answering page, pausing as Inactive', ['extension' => $extension]);
        $this->sendInactivePause($extension);
    }

    private function sendInactivePause(string $extension): void
    {
        $this->agentCommands->pauseAgent($extension, PauseReason::Inactive)->catch(
            fn(AmiActionFailed|AmiActionTimedOut $exception) => $this->logger->warning(
                'The exchange did not confirm the Inactive pause of agent {extension}: {error}',
                ['extension' => $extension, 'error' => $exception->getMessage()],
            ),
        )->catch(function (AmiConnectionLost $exception) use ($extension): void {
            if ($this->hasConnectionOfAgent($extension)) {
                return;
            }
            $this->inactivePauseTimers[$extension] = $this->loop->addTimer($this->settings->pingIntervalSeconds, function () use ($extension): void {
                unset($this->inactivePauseTimers[$extension]);
                if ($this->isKnownAgentUnpaused($extension)) {
                    $this->sendInactivePause($extension);
                }
            });
        });
    }

    private function isKnownAgentUnpaused(string $extension): bool
    {
        $agent = $this->floor->findAgent($extension);

        return $agent !== null && $agent->pauseReason === null;
    }

    private function hasConnectionOfAgent(string $extension): bool
    {
        return array_any($this->connections, fn(ClientConnection $connection): bool => $connection->agentExtension === $extension);
    }
}
