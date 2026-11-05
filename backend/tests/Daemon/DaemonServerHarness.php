<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Daemon;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use QueueWallboard\Daemon\AgentCommands;
use QueueWallboard\Daemon\Ami\AmiActionSender;
use QueueWallboard\Daemon\DaemonSettings;
use QueueWallboard\Daemon\Floor\FloorState;
use QueueWallboard\Daemon\Heartbeat;
use QueueWallboard\Daemon\HttpRouter;
use QueueWallboard\Daemon\LongPoll\ChangeHistory;
use QueueWallboard\Daemon\LongPoll\LongPollEndpoint;
use QueueWallboard\Daemon\WebSocket\WebSocketServer;
use QueueWallboard\Daemon\Wire\WireMapper;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Socket\SocketServer;

final class DaemonServerHarness
{
    private const array WEB_ORIGINS = ['http://localhost:3000', 'http://127.0.0.1:3000'];
    public const string BOOT_ID = 'harness';

    public readonly WebSocketServer $webSocketServer;
    public readonly LongPollEndpoint $longPollEndpoint;
    public readonly string $address;
    public readonly Heartbeat $heartbeat;
    private readonly SocketServer $httpSocketServer;

    /** @var list<WebSocketTestClient> */
    private array $webSocketClients = [];

    public function __construct(
        FloorState $floor,
        AmiActionSender $amiActionSender,
        DaemonSettings $settings,
        ClockInterface $clock,
        LoggerInterface $logger,
    ) {
        $agentCommands = new AgentCommands($amiActionSender);
        $this->heartbeat = new Heartbeat(Loop::get(), $settings, $clock, $floor, $agentCommands, $logger);
        $wireMapper = new WireMapper($floor);
        $this->webSocketServer = new WebSocketServer(Loop::get(), $floor, $wireMapper, $this->heartbeat, $agentCommands, $clock, $logger, self::WEB_ORIGINS);
        $this->httpSocketServer = new SocketServer('127.0.0.1:0');
        $this->longPollEndpoint = new LongPollEndpoint(
            Loop::get(),
            new ChangeHistory(self::BOOT_ID, $settings->longPollHistoryLength),
            $wireMapper,
            $settings->longPollSeconds,
        );
        new HttpServer(new HttpRouter($this->webSocketServer, $this->longPollEndpoint)->handleRequest(...))->listen($this->httpSocketServer);
        $this->address = $this->httpSocketServer->getAddress() ?? '';
    }

    /**
     * @param array<string, string> $headers
     */
    public function openWebSocket(string $pathAndQuery, array $headers = []): WebSocketTestClient
    {
        $webSocketClient = WebSocketTestClient::open($this->address, $pathAndQuery, $headers);
        $this->webSocketClients[] = $webSocketClient;

        return $webSocketClient;
    }

    public function close(): void
    {
        foreach ($this->webSocketClients as $webSocketClient) {
            $webSocketClient->close();
        }
        $this->httpSocketServer->close();
        $this->heartbeat->stop();
    }
}
