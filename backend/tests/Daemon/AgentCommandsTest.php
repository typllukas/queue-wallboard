<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Daemon;

use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueueWallboard\Ami\AmiPacket;
use QueueWallboard\Ami\AmiPacketParser;
use QueueWallboard\Daemon\AgentCommands;
use QueueWallboard\Daemon\Ami\AmiActionFailed;
use QueueWallboard\Daemon\Ami\AmiActionSender;
use QueueWallboard\Daemon\Ami\AmiActionTimedOut;
use QueueWallboard\Daemon\Ami\AmiClient;
use QueueWallboard\Daemon\Ami\AmiConnectionLost;
use QueueWallboard\Daemon\Ami\AmiEventHandler;
use QueueWallboard\Daemon\Ami\QueueStatusResync;
use QueueWallboard\Daemon\Broadcaster;
use QueueWallboard\Daemon\DaemonSettings;
use QueueWallboard\Daemon\Floor\Agent;
use QueueWallboard\Daemon\Floor\ExtensionStatus;
use QueueWallboard\Daemon\Floor\FloorState;
use QueueWallboard\Daemon\Floor\QueueSettings;
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
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use Symfony\Component\Clock\MockClock;

use function React\Async\delay;

#[CoversClass(AgentCommands::class)]
#[CoversClass(WebSocketServer::class)]
final class AgentCommandsTest extends TestCase
{
    private const string USERNAME = 'demo';
    private const string SECRET = 'demo-secret';
    private const string NOT_CONFIRMED_ERROR = 'The PBX did not confirm the pause. Try again.';
    private const string END_OF_PAUSE_NOT_CONFIRMED_ERROR = 'The PBX did not confirm the end of the pause. Try again.';
    private const float SETTLE_SECONDS = 0.1;

    private MockClock $clock;
    private FloorState $floor;
    private RecordingLogger $logger;
    private RecordingAmiActionSender $amiActionSender;
    private ?DaemonServerHarness $daemonServer = null;
    private ?CallCentre $callCentre = null;
    private ?AmiServer $amiServer = null;
    private ?AmiClient $amiClient = null;

    #[Override]
    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-03 10:00:00 Europe/Prague');
        $this->floor = new FloorState(QueueSettings::createDemoSettings(), $this->clock);
        $this->logger = new RecordingLogger();
        $this->amiActionSender = new RecordingAmiActionSender();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->daemonServer?->close();
        $this->amiClient?->close();
        $this->amiServer?->close();
        $this->callCentre?->stop();
    }

    public function testLunchSendsQueuePauseForAllQueues(): void
    {
        $this->callCentre = new CallCentre(
            Floor::createDemoFloor(),
            SimulationTimings::createDemoTimings(),
            Loop::get(),
            new Randomizer(new Mt19937(1)),
            $this->clock,
        );
        $amiSocketServer = new SocketServer('127.0.0.1:0');
        $this->amiServer = new AmiServer($amiSocketServer, $this->callCentre, self::USERNAME, self::SECRET);
        $actionsReceivedBySimulator = '';
        $amiSocketServer->on('connection', static function (ConnectionInterface $connection) use (&$actionsReceivedBySimulator): void {
            $connection->on('data', static function (string $chunk) use (&$actionsReceivedBySimulator): void {
                $actionsReceivedBySimulator .= $chunk;
            });
        });
        $eventHandler = new AmiEventHandler($this->floor, $this->clock);
        $this->amiClient = new AmiClient(
            $amiSocketServer->getAddress() ?? '',
            self::USERNAME,
            self::SECRET,
            Loop::get(),
            new QueueStatusResync($this->floor, $eventHandler, $this->clock, $this->logger),
            $eventHandler,
            $this->logger,
            replyTimeoutSeconds: 1.0,
            resyncTimeoutSeconds: 2.0,
            keepaliveIntervalSeconds: 60.0,
            reconnectDelaysSeconds: [0.05],
        );
        $daemonServer = $this->startDaemonServer($this->amiClient);
        $this->amiClient->registerFloorChangeListener(new Broadcaster($this->floor, new WireMapper($this->floor), $daemonServer->webSocketServer, $daemonServer->longPollEndpoint)->flushChanges(...));
        $this->amiClient->connect();
        WebSocketTestClient::awaitCondition(fn(): bool => $this->floor->isExchangeConnected());
        $wallboardClient = $daemonServer->openWebSocket('/websocket');
        $agentPageClient = $daemonServer->openWebSocket('/websocket?agent=203');
        $wallboardClient->awaitMessages(1);
        $agentPageClient->awaitMessages(1);

        $agentPageClient->sendClientMessage(['action' => 'pause', 'pause' => 'lunch']);

        foreach ([$wallboardClient, $agentPageClient] as $webSocketClient) {
            WebSocketTestClient::awaitCondition(static fn(): bool => $webSocketClient->findNewestPauseOfAgent('203') === 'lunch');
            self::assertSame('lunch', $webSocketClient->findNewestPauseOfAgent('203'));
        }
        $queuePausePackets = array_values(array_filter(
            new AmiPacketParser()->pushChunk($actionsReceivedBySimulator),
            static fn(AmiPacket $packet): bool => $packet->findField('Action') === 'QueuePause',
        ));
        self::assertCount(1, $queuePausePackets);
        self::assertSame('PJSIP/203', $queuePausePackets[0]->findField('Interface'));
        self::assertSame('true', $queuePausePackets[0]->findField('Paused'));
        self::assertSame('Lunch', $queuePausePackets[0]->findField('Reason'));
        self::assertNull($queuePausePackets[0]->findField('Queue'));
    }

    public function testUnpauseSendsQueuePauseWithPausedFalse(): void
    {
        $agentPageClient = $this->openAgentPageOfAgent203();

        $agentPageClient->sendClientMessage(['action' => 'unpause']);
        WebSocketTestClient::awaitCondition(fn(): bool => $this->amiActionSender->sentActions !== []);

        self::assertSame(
            [['action' => 'QueuePause', 'fields' => ['Interface' => 'PJSIP/203', 'Paused' => 'false']]],
            $this->amiActionSender->sentActions,
        );
    }

    public function testAnErrorFromTheExchangeReachesOnlyTheAskingConnection(): void
    {
        $this->amiActionSender->failEveryActionWith(new AmiActionFailed('Interface not found'));
        $agentPageClient = $this->openAgentPageOfAgent203();
        $wallboardClient = $this->openWallboard();

        $agentPageClient->sendClientMessage(['action' => 'pause', 'pause' => 'training']);
        $agentPageClient->awaitMessages(2);
        delay(self::SETTLE_SECONDS);

        self::assertSame(['action' => 'error', 'message' => self::NOT_CONFIRMED_ERROR], $agentPageClient->receivedMessages[1] ?? null);
        self::assertCount(1, $wallboardClient->receivedMessages);
        self::assertFalse($agentPageClient->isClosed);
        $logRecords = $this->logger->findRecords('The exchange did not confirm QueuePause for agent {extension}: {error}');
        self::assertCount(1, $logRecords);
        self::assertSame(['extension' => '203', 'error' => 'Interface not found'], $logRecords[0]['context']);
    }

    public function testALostExchangeConnectionGivesTheSameError(): void
    {
        $this->amiActionSender->failEveryActionWith(new AmiConnectionLost('AMI action QueuePause not sent: not logged in to the exchange.'));
        $agentPageClient = $this->openAgentPageOfAgent203();

        $agentPageClient->sendClientMessage(['action' => 'pause', 'pause' => 'lunch']);
        $agentPageClient->awaitMessages(2);

        self::assertSame(['action' => 'error', 'message' => self::NOT_CONFIRMED_ERROR], $agentPageClient->receivedMessages[1] ?? null);
        self::assertFalse($agentPageClient->isClosed);
    }

    public function testATimedOutUnpauseGetsTheEndOfPauseError(): void
    {
        $this->amiActionSender->failEveryActionWith(new AmiActionTimedOut('AMI action QueuePause got no reply within 5 s.'));
        $agentPageClient = $this->openAgentPageOfAgent203();

        $agentPageClient->sendClientMessage(['action' => 'unpause']);
        $agentPageClient->awaitMessages(2);

        self::assertSame(['action' => 'error', 'message' => self::END_OF_PAUSE_NOT_CONFIRMED_ERROR], $agentPageClient->receivedMessages[1] ?? null);
    }

    public function testAPauseFromTheWallboardGetsAnErrorAndNoAction(): void
    {
        $this->addAgent203();
        $this->startDaemonServer($this->amiActionSender);
        $wallboardClient = $this->openWallboard();

        $wallboardClient->sendClientMessage(['action' => 'pause', 'pause' => 'lunch']);
        $wallboardClient->awaitMessages(2);

        self::assertSame(['action' => 'error', 'message' => 'This connection has no agent'], $wallboardClient->receivedMessages[1] ?? null);
        self::assertSame([], $this->amiActionSender->sentActions);
        self::assertFalse($wallboardClient->isClosed);
    }

    public function testAnInvalidMessageGetsAnErrorAndTheConnectionStaysOpen(): void
    {
        $agentPageClient = $this->openAgentPageOfAgent203();

        $agentPageClient->sendClientMessage(['action' => 'pause', 'pause' => 'inactive']);
        $agentPageClient->sendFrame(Frame::OP_TEXT, '{not json');
        $agentPageClient->awaitMessages(3);

        self::assertSame(['action' => 'error', 'message' => 'Invalid message'], $agentPageClient->receivedMessages[1] ?? null);
        self::assertSame(['action' => 'error', 'message' => 'Invalid message'], $agentPageClient->receivedMessages[2] ?? null);
        self::assertSame([], $this->amiActionSender->sentActions);
        self::assertFalse($agentPageClient->isClosed);
    }

    public function testAPauseAfterACloseInTheSameChunkIsIgnored(): void
    {
        $agentPageClient = $this->openAgentPageOfAgent203();

        $agentPageClient->sendFramesInOneChunk(
            new Frame(pack('n', Frame::CLOSE_GOING_AWAY), true, Frame::OP_CLOSE),
            new Frame(json_encode(['action' => 'pause', 'pause' => 'lunch'], JSON_THROW_ON_ERROR), true, Frame::OP_TEXT),
        );
        WebSocketTestClient::awaitCondition(fn(): bool => $agentPageClient->isClosed);
        delay(self::SETTLE_SECONDS);

        self::assertTrue($agentPageClient->isClosed);
        self::assertSame([], $this->amiActionSender->sentActions);
    }

    private function startDaemonServer(AmiActionSender $amiActionSender): DaemonServerHarness
    {
        $this->daemonServer = new DaemonServerHarness(
            $this->floor,
            $amiActionSender,
            new DaemonSettings(
                pingIntervalSeconds: 0.5,
                pongTimeoutSeconds: 1.0,
                agentGraceSeconds: 1.0,
                amiReplyTimeoutSeconds: 1.0,
                resyncTimeoutSeconds: 2.0,
                amiKeepaliveIntervalSeconds: 60.0,
                longPollSeconds: 1.0,
                longPollHistoryLength: 10,
            ),
            $this->clock,
            $this->logger,
        );

        return $this->daemonServer;
    }

    private function openAgentPageOfAgent203(): WebSocketTestClient
    {
        $this->addAgent203();
        $agentPageClient = $this->startDaemonServer($this->amiActionSender)->openWebSocket('/websocket?agent=203');
        $agentPageClient->awaitMessages(1);

        return $agentPageClient;
    }

    private function openWallboard(): WebSocketTestClient
    {
        $wallboardClient = ($this->daemonServer ?? throw new LogicException('Start the daemon server first.'))->openWebSocket('/websocket');
        $wallboardClient->awaitMessages(1);

        return $wallboardClient;
    }

    private function addAgent203(): void
    {
        $this->floor->addAgent(new Agent('203', 'Agent 203', ['sales', 'support'], $this->clock->now(), ExtensionStatus::Idle, null, null, null, $this->clock->now()));
    }
}
