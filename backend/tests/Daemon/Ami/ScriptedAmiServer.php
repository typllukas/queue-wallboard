<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Daemon\Ami;

use Closure;
use QueueWallboard\Ami\AmiPacket;
use QueueWallboard\Ami\AmiPacketParser;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;

final class ScriptedAmiServer
{
    public const string BANNER = "Asterisk Call Manager/11.0.0\r\n";

    public private(set) int $connectionCount = 0;
    private readonly SocketServer $socketServer;

    /** @var array<int, ConnectionInterface> */
    private array $connections = [];

    /**
     * @param Closure(ConnectionInterface): void $greetConnection
     * @param Closure(AmiPacket, ConnectionInterface): void $answerAction
     */
    public function __construct(string $address, Closure $greetConnection, Closure $answerAction)
    {
        $this->socketServer = new SocketServer($address);
        $this->socketServer->on('connection', function (ConnectionInterface $connection) use ($greetConnection, $answerAction): void {
            ++$this->connectionCount;
            $connectionKey = spl_object_id($connection);
            $this->connections[$connectionKey] = $connection;
            $connection->on('close', function () use ($connectionKey): void {
                unset($this->connections[$connectionKey]);
            });
            $parser = new AmiPacketParser();
            $connection->on('data', static function (string $chunk) use ($parser, $connection, $answerAction): void {
                foreach ($parser->pushChunk($chunk) as $actionPacket) {
                    $answerAction($actionPacket, $connection);
                }
            });
            $greetConnection($connection);
        });
    }

    public static function writeBanner(ConnectionInterface $connection): void
    {
        $connection->write(self::BANNER);
    }

    public static function writeSuccessReply(ConnectionInterface $connection, AmiPacket $actionPacket, string $message): void
    {
        $connection->write(new AmiPacket([
            'Response' => 'Success',
            'ActionID' => $actionPacket->findActionId() ?? '',
            'Message' => $message,
        ])->toWire());
    }

    public static function writeEmptyQueueStatus(ConnectionInterface $connection, AmiPacket $actionPacket): void
    {
        $actionId = $actionPacket->findActionId() ?? '';
        $connection->write(
            new AmiPacket(['Response' => 'Success', 'ActionID' => $actionId, 'EventList' => 'start', 'Message' => 'Queue status will follow'])->toWire()
            . new AmiPacket(['Event' => 'QueueStatusComplete', 'ActionID' => $actionId, 'EventList' => 'Complete', 'ListItems' => '0'])->toWire(),
        );
    }

    public function getAddress(): string
    {
        return $this->socketServer->getAddress() ?? '';
    }

    public function close(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        $this->socketServer->close();
    }
}
