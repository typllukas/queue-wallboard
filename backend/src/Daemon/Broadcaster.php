<?php

declare(strict_types=1);

namespace QueueWallboard\Daemon;

use QueueWallboard\Daemon\Floor\FloorState;
use QueueWallboard\Daemon\LongPoll\LongPollEndpoint;
use QueueWallboard\Daemon\WebSocket\WebSocketServer;
use QueueWallboard\Daemon\Wire\WireMapper;

final readonly class Broadcaster
{
    public function __construct(
        private FloorState $floor,
        private WireMapper $wireMapper,
        private WebSocketServer $webSocketServer,
        private LongPollEndpoint $longPollEndpoint,
    ) {}

    public function flushChanges(): void
    {
        $changes = $this->floor->takeChanges();
        $this->longPollEndpoint->publishChanges($changes);
        if ($changes->isLiveStateReplaced) {
            $snapshot = $this->wireMapper->mapSnapshot();
            foreach ($this->webSocketServer->listConnections() as $connection) {
                $connection->sendSnapshot($snapshot);
            }

            return;
        }
        $agents = $this->wireMapper->mapChangedAgents($changes);
        $queues = $this->wireMapper->mapChangedQueues($changes);

        foreach ($this->webSocketServer->listConnections() as $connection) {
            $connection->sendChanges($agents, $queues);
            if ($changes->isExchangeConnectionChanged) {
                $connection->sendMessage(['action' => 'exchangeConnection', 'connected' => $this->floor->isExchangeConnected()]);
            }
        }
    }
}
