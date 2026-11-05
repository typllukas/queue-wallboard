<?php

declare(strict_types=1);

namespace QueueWallboard\Simulator;

use React\Socket\ConnectionInterface;
use React\Socket\ServerInterface;

final class AmiServer
{
    /** @var array<int, AmiSession> */
    private array $sessions = [];

    private bool $isInOutage = false;

    public function __construct(
        private readonly ServerInterface $socketServer,
        CallCentre $callCentre,
        string $username,
        string $secret,
    ) {
        $socketServer->on('connection', function (ConnectionInterface $connection) use ($callCentre, $username, $secret): void {
            if ($this->isInOutage) {
                $connection->close();

                return;
            }
            $sessionKey = spl_object_id($connection);
            $this->sessions[$sessionKey] = new AmiSession($connection, $callCentre, $username, $secret);
            $connection->on('close', function () use ($sessionKey): void {
                unset($this->sessions[$sessionKey]);
            });
        });
    }

    public function startOutage(): void
    {
        $this->isInOutage = true;
        foreach ($this->sessions as $session) {
            $session->close();
        }
    }

    public function endOutage(): void
    {
        $this->isInOutage = false;
    }

    public function close(): void
    {
        foreach ($this->sessions as $session) {
            $session->close();
        }
        $this->socketServer->close();
    }
}
