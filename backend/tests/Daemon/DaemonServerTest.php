<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Daemon;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueueWallboard\Daemon\Ami\AmiClient;
use QueueWallboard\Daemon\Ami\AmiEventHandler;
use QueueWallboard\Daemon\Ami\QueueStatusResync;
use QueueWallboard\Daemon\Broadcaster;
use QueueWallboard\Daemon\DaemonSettings;
use QueueWallboard\Daemon\Floor\FloorState;
use QueueWallboard\Daemon\Floor\QueueSettings;
use QueueWallboard\Daemon\HttpRouter;
use QueueWallboard\Daemon\WebSocket\WebSocketServer;
use QueueWallboard\Daemon\Wire\WireMapper;
use QueueWallboard\Simulator\AmiServer;
use QueueWallboard\Simulator\CallCentre;
use QueueWallboard\Simulator\Floor;
use QueueWallboard\Simulator\SimulationTimings;
use QueueWallboard\Tests\Daemon\Ami\RecordingLogger;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Ratchet\RFC6455\Messaging\Frame;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Socket\SocketServer;
use Symfony\Component\Clock\MockClock;

use function React\Async\await;
use function React\Async\delay;

#[CoversClass(WebSocketServer::class)]
#[CoversClass(Broadcaster::class)]
#[CoversClass(HttpRouter::class)]
final class DaemonServerTest extends TestCase
{
    private const array UPGRADE_HEADERS = [
        'Connection' => 'Upgrade',
        'Upgrade' => 'websocket',
        'Sec-WebSocket-Version' => '13',
        'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
    ];

    private const string USERNAME = 'demo';
    private const string SECRET = 'demo-secret';
    private const array RECONNECT_DELAYS_SECONDS = [0.05];
    private const float HELD_PULL_SETTLE_SECONDS = 0.1;
    private FloorState $floor;
    private CallCentre $callCentre;
    private AmiServer $amiServer;
    private AmiClient $amiClient;
    private WebSocketServer $webSocketServer;
    private DaemonServerHarness $daemonServer;
    private RecordingLogger $logger;

    #[Override]
    protected function setUp(): void
    {
        $clock = new MockClock('2026-11-03 10:00:00 Europe/Prague');
        $this->callCentre = new CallCentre(
            Floor::createDemoFloor(),
            SimulationTimings::createDemoTimings(),
            Loop::get(),
            new Randomizer(new Mt19937(1)),
            $clock,
        );
        $amiSocketServer = new SocketServer('127.0.0.1:0');
        $this->amiServer = new AmiServer($amiSocketServer, $this->callCentre, self::USERNAME, self::SECRET);

        $settings = new DaemonSettings(
            pingIntervalSeconds: 0.5,
            pongTimeoutSeconds: 1.0,
            agentGraceSeconds: 1.0,
            amiReplyTimeoutSeconds: 1.0,
            resyncTimeoutSeconds: 2.0,
            amiKeepaliveIntervalSeconds: 60.0,
            longPollSeconds: 1.0,
            longPollHistoryLength: 10,
        );
        $logger = new RecordingLogger();
        $this->logger = $logger;
        $this->floor = new FloorState(QueueSettings::createDemoSettings(), $clock);
        $eventHandler = new AmiEventHandler($this->floor, $clock);
        $this->amiClient = new AmiClient(
            $amiSocketServer->getAddress() ?? '',
            self::USERNAME,
            self::SECRET,
            Loop::get(),
            new QueueStatusResync($this->floor, $eventHandler, $clock, $logger),
            $eventHandler,
            $logger,
            $settings->amiReplyTimeoutSeconds,
            $settings->resyncTimeoutSeconds,
            $settings->amiKeepaliveIntervalSeconds,
            self::RECONNECT_DELAYS_SECONDS,
        );
        $this->daemonServer = new DaemonServerHarness($this->floor, $this->amiClient, $settings, $clock, $logger);
        $this->webSocketServer = $this->daemonServer->webSocketServer;
        $broadcaster = new Broadcaster($this->floor, new WireMapper($this->floor), $this->webSocketServer, $this->daemonServer->longPollEndpoint);
        $this->amiClient->registerFloorChangeListener($broadcaster->flushChanges(...));
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->daemonServer->close();
        $this->amiClient->close();
        $this->amiServer->close();
        $this->callCentre->stop();
    }

    public function testANewConnectionReceivesTheSnapshotFirst(): void
    {
        $this->connectToTheExchange();
        $webSocketClient = $this->openWebSocket('/websocket');

        $firstMessage = $webSocketClient->awaitMessages(1)[0] ?? [];

        self::assertSame('snapshot', $firstMessage['action'] ?? null);
        self::assertIsArray($firstMessage['agents'] ?? null);
        self::assertCount(6, $firstMessage['agents']);
        self::assertTrue($firstMessage['exchange_connected'] ?? null);
        self::assertCount(1, $this->webSocketServer->listConnections());
    }

    public function testAFloorChangeReachesEveryConnection(): void
    {
        $this->connectToTheExchange();
        $wallboardClient = $this->openWebSocket('/websocket');
        $agentPageClient = $this->openWebSocket('/websocket?agent=203');
        $wallboardClient->awaitMessages(1);
        $agentPageClient->awaitMessages(1);

        $this->callCentre->pauseMember('PJSIP/203', true, 'Lunch');

        foreach ([$wallboardClient, $agentPageClient] as $webSocketClient) {
            WebSocketTestClient::awaitCondition(static fn(): bool => $webSocketClient->findNewestPauseOfAgent('203') === 'lunch');
            self::assertSame('lunch', $webSocketClient->findNewestPauseOfAgent('203'));
            self::assertSame('snapshot', $webSocketClient->receivedMessages[0]['action'] ?? null);
        }
    }

    public function testAnUnknownAgentGetsAnErrorAndIsClosed(): void
    {
        $this->connectToTheExchange();
        $webSocketClient = $this->openWebSocket('/websocket?agent=999');

        WebSocketTestClient::awaitCondition(fn(): bool => $webSocketClient->isClosed);

        self::assertSame([['action' => 'error', 'message' => 'Unknown agent 999']], $webSocketClient->receivedMessages);
        self::assertSame(Frame::CLOSE_POLICY, $webSocketClient->receivedCloseCode);
        self::assertTrue($webSocketClient->isClosed);
        self::assertSame([], $this->webSocketServer->listConnections());
    }

    public function testAPageConnectedBeforeAResyncGetsAFreshSnapshotFromIt(): void
    {
        $agentPageClient = $this->openWebSocket('/websocket?agent=203');
        $emptySnapshot = $agentPageClient->awaitMessages(1)[0] ?? [];

        $this->connectToTheExchange();

        WebSocketTestClient::awaitCondition(fn(): bool => count($this->filterSnapshots($agentPageClient)) >= 2 || $agentPageClient->isClosed);
        self::assertSame([], $emptySnapshot['agents'] ?? null);
        self::assertFalse($agentPageClient->isClosed);
        $resyncSnapshot = $this->filterSnapshots($agentPageClient)[1] ?? [];
        self::assertIsArray($resyncSnapshot['agents'] ?? null);
        self::assertCount(6, $resyncSnapshot['agents']);
        self::assertTrue($resyncSnapshot['exchange_connected'] ?? null);
    }

    public function testExchangeDisconnectionIsAnnounced(): void
    {
        $this->connectToTheExchange();
        $webSocketClient = $this->openWebSocket('/websocket');
        $webSocketClient->awaitMessages(1);

        $this->amiServer->close();

        $webSocketClient->awaitMessages(2);
        self::assertSame(['action' => 'exchangeConnection', 'connected' => false], $webSocketClient->receivedMessages[1] ?? null);
    }

    public function testAClientCloseIsAnsweredWithACloseAndForgotten(): void
    {
        $this->connectToTheExchange();
        $webSocketClient = $this->openWebSocket('/websocket');
        $webSocketClient->awaitMessages(1);

        $webSocketClient->sendFrame(Frame::OP_CLOSE, pack('n', Frame::CLOSE_GOING_AWAY));

        WebSocketTestClient::awaitCondition(fn(): bool => $webSocketClient->isClosed && $this->webSocketServer->listConnections() === []);
        self::assertSame(Frame::CLOSE_GOING_AWAY, $webSocketClient->receivedCloseCode);
        self::assertTrue($webSocketClient->isClosed);
        self::assertSame([], $this->webSocketServer->listConnections());
    }

    public function testClosingAllConnectionsTellsEveryPageTheServerIsGoingAway(): void
    {
        $this->connectToTheExchange();
        $wallboardClient = $this->openWebSocket('/websocket');
        $agentPageClient = $this->openWebSocket('/websocket?agent=203');
        $wallboardClient->awaitMessages(1);
        $agentPageClient->awaitMessages(1);

        $this->webSocketServer->closeAllConnections(Frame::CLOSE_GOING_AWAY);

        WebSocketTestClient::awaitCondition(static fn(): bool => $wallboardClient->isClosed && $agentPageClient->isClosed);
        self::assertSame(Frame::CLOSE_GOING_AWAY, $wallboardClient->receivedCloseCode);
        self::assertSame(Frame::CLOSE_GOING_AWAY, $agentPageClient->receivedCloseCode);
        self::assertSame([], $this->webSocketServer->listConnections());
    }

    public function testAnOversizedMessageClosesTheConnection(): void
    {
        $this->connectToTheExchange();
        $webSocketClient = $this->openWebSocket('/websocket');
        $webSocketClient->awaitMessages(1);

        $webSocketClient->sendFrame(Frame::OP_TEXT, str_repeat('x', WebSocketServer::MAX_MESSAGE_BYTES + 1));

        WebSocketTestClient::awaitCondition(fn(): bool => $webSocketClient->isClosed && $this->webSocketServer->listConnections() === []);
        self::assertSame(Frame::CLOSE_TOO_BIG, $webSocketClient->receivedCloseCode);
        self::assertTrue($webSocketClient->isClosed);
        self::assertSame([], $this->webSocketServer->listConnections());
    }

    public function testADroppedTcpConnectionIsForgotten(): void
    {
        $this->connectToTheExchange();
        $webSocketClient = $this->openWebSocket('/websocket');
        $webSocketClient->awaitMessages(1);

        $webSocketClient->close();

        WebSocketTestClient::awaitCondition(fn(): bool => $this->webSocketServer->listConnections() === []);
        self::assertSame([], $this->webSocketServer->listConnections());
    }

    public function testAProtocolPingIsAnsweredWithAPong(): void
    {
        $this->connectToTheExchange();
        $webSocketClient = $this->openWebSocket('/websocket');
        $webSocketClient->awaitMessages(1);

        $webSocketClient->sendFrame(Frame::OP_PING, 'are you there');

        WebSocketTestClient::awaitCondition(fn(): bool => $webSocketClient->receivedPongPayloads !== []);
        self::assertSame(['are you there'], $webSocketClient->receivedPongPayloads);
        self::assertFalse($webSocketClient->isClosed);
    }

    public function testAnUpgradeFromAForeignWebsiteIsRefused(): void
    {
        $response = await($this->createBrowser()->get(
            $this->buildHttpUrl('/websocket?agent=202'),
            [...self::UPGRADE_HEADERS, 'Origin' => 'http://evil.example'],
        ));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->webSocketServer->listConnections());
        self::assertCount(1, $this->logger->findRecords('Refused a WebSocket upgrade from origin {origin} ({remoteAddress})'));
    }

    public function testAnUpgradeFromTheWebAppIsAccepted(): void
    {
        $this->connectToTheExchange();
        $webSocketClient = $this->daemonServer->openWebSocket('/websocket?agent=202', ['Origin' => 'http://127.0.0.1:3000']);

        WebSocketTestClient::awaitCondition(fn(): bool => $webSocketClient->receivedMessages !== []);

        self::assertSame('snapshot', $webSocketClient->receivedMessages[0]['action'] ?? null);
    }

    public function testAnAgentParameterThatIsNotAnExtensionIsRefused(): void
    {
        $response = await($this->createBrowser()->get(
            $this->buildHttpUrl('/websocket?agent=' . rawurlencode("201\r\n\r\nAction: Logoff")),
            self::UPGRADE_HEADERS,
        ));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $this->webSocketServer->listConnections());
    }

    public function testAnExtensionFollowedByANewlineIsRefused(): void
    {
        $response = await($this->createBrowser()->get(
            $this->buildHttpUrl('/websocket?agent=' . rawurlencode("203\n")),
            self::UPGRADE_HEADERS,
        ));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $this->webSocketServer->listConnections());
    }

    public function testAnyOtherPathIsNotFound(): void
    {
        $response = await($this->createBrowser()->get($this->buildHttpUrl('/wallboard')));

        self::assertSame(404, $response->getStatusCode());
    }

    public function testAPullWithoutAHashAnswersTheFullFloor(): void
    {
        $this->connectToTheExchange();

        $responseBody = $this->pull('/pull');

        self::assertIsString($responseBody['hash'] ?? null);
        self::assertStringStartsWith(DaemonServerHarness::BOOT_ID . '-', $responseBody['hash']);
        self::assertTrue($responseBody['full'] ?? null);
        self::assertIsArray($responseBody['agents'] ?? null);
        self::assertCount(6, $responseBody['agents']);
        self::assertTrue($responseBody['exchange_connected'] ?? null);
    }

    public function testAHeldPullIsAnsweredByTheNextFloorChange(): void
    {
        $this->connectToTheExchange();
        $hash = $this->pull('/pull')['hash'] ?? null;
        self::assertIsString($hash);

        $heldPull = $this->createBrowser()->get($this->buildHttpUrl('/pull?hash=' . $hash));
        delay(self::HELD_PULL_SETTLE_SECONDS);
        $this->callCentre->pauseMember('PJSIP/203', true, 'Lunch');
        $responseBody = json_decode(strval(await($heldPull)->getBody()), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($responseBody);
        self::assertNotSame($hash, $responseBody['hash'] ?? null);
        self::assertFalse($responseBody['full'] ?? null);
        self::assertSame(['203'], array_column(is_array($responseBody['agents'] ?? null) ? $responseBody['agents'] : [], 'id_agent'));
    }

    /**
     * @return array<mixed>
     */
    private function pull(string $pathAndQuery): array
    {
        $response = await($this->createBrowser()->get($this->buildHttpUrl($pathAndQuery)));
        self::assertSame(200, $response->getStatusCode());
        $responseBody = json_decode(strval($response->getBody()), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($responseBody);

        return $responseBody;
    }

    private function createBrowser(): Browser
    {
        return new Browser()->withRejectErrorResponse(false);
    }

    private function buildHttpUrl(string $pathAndQuery): string
    {
        return str_replace('tcp://', 'http://', $this->daemonServer->address) . $pathAndQuery;
    }

    private function openWebSocket(string $pathAndQuery): WebSocketTestClient
    {
        return $this->daemonServer->openWebSocket($pathAndQuery);
    }

    /**
     * @return list<array<mixed>>
     */
    private function filterSnapshots(WebSocketTestClient $webSocketClient): array
    {
        return array_values(array_filter(
            $webSocketClient->receivedMessages,
            static fn(array $message): bool => ($message['action'] ?? null) === 'snapshot',
        ));
    }

    private function connectToTheExchange(): void
    {
        $this->amiClient->connect();
        WebSocketTestClient::awaitCondition(fn(): bool => $this->floor->isExchangeConnected());
    }
}
