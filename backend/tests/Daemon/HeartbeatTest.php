<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Daemon;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueueWallboard\Daemon\Ami\AmiActionFailed;
use QueueWallboard\Daemon\Ami\AmiConnectionLost;
use QueueWallboard\Daemon\DaemonSettings;
use QueueWallboard\Daemon\Floor\Agent;
use QueueWallboard\Daemon\Floor\ExtensionStatus;
use QueueWallboard\Daemon\Floor\FloorState;
use QueueWallboard\Daemon\Floor\PauseReason;
use QueueWallboard\Daemon\Floor\QueueSettings;
use QueueWallboard\Daemon\Heartbeat;
use QueueWallboard\Tests\Daemon\Ami\RecordingLogger;
use Ratchet\RFC6455\Messaging\Frame;
use Symfony\Component\Clock\NativeClock;

use function React\Async\delay;

#[CoversClass(Heartbeat::class)]
final class HeartbeatTest extends TestCase
{
    private const float PING_INTERVAL_SECONDS = 0.05;
    private const float PONG_TIMEOUT_SECONDS = 0.2;
    private const float AGENT_GRACE_SECONDS = 0.2;
    private const float LONG_AGENT_GRACE_SECONDS = 1.0;
    private const float QUIET_PERIOD_SECONDS = self::PONG_TIMEOUT_SECONDS + self::PING_INTERVAL_SECONDS + self::AGENT_GRACE_SECONDS * 2;

    private NativeClock $clock;
    private FloorState $floor;
    private RecordingAmiActionSender $amiActionSender;
    private RecordingLogger $logger;
    private DaemonServerHarness $daemonServer;

    #[Override]
    protected function setUp(): void
    {
        $this->clock = new NativeClock();
        $this->floor = new FloorState(QueueSettings::createDemoSettings(), $this->clock);
        $this->amiActionSender = new RecordingAmiActionSender();
        $this->logger = new RecordingLogger();
        $this->daemonServer = $this->createDaemonServer(self::AGENT_GRACE_SECONDS);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->daemonServer->close();
    }

    public function testASilentAgentPageGetsTheAgentPausedAsInactive(): void
    {
        $this->addAgent('203', null);
        $agentPageClient = $this->daemonServer->openWebSocket('/websocket?agent=203');
        $agentPageClient->stopAnsweringPings();

        WebSocketTestClient::awaitCondition(fn(): bool => $agentPageClient->isClosed);

        self::assertSame(
            [['action' => 'QueuePause', 'fields' => ['Interface' => 'PJSIP/203', 'Paused' => 'true', 'Reason' => 'Inactive']]],
            $this->amiActionSender->sentActions,
        );
        self::assertTrue($agentPageClient->isClosed);
        self::assertGreaterThan(0, $agentPageClient->receivedPingCount);
        self::assertSame([], $this->daemonServer->webSocketServer->listConnections());
        self::assertCount(1, $this->logger->findRecords('Closing a WebSocket connection silent for {pongTimeoutSeconds} s (agent {extension})'));
    }

    public function testAPageThatClosesItselfGetsTheAgentPausedOnlyAfterTheGrace(): void
    {
        $this->restartWithAgentGrace(self::LONG_AGENT_GRACE_SECONDS);
        $this->addAgent('203', null);
        $this->daemonServer->openWebSocket('/websocket?agent=203')->close();

        delay(self::LONG_AGENT_GRACE_SECONDS / 2);
        $actionsWithinTheGrace = $this->amiActionSender->sentActions;
        WebSocketTestClient::awaitCondition(fn(): bool => $this->amiActionSender->sentActions !== []);

        self::assertSame([], $actionsWithinTheGrace);
        self::assertSame(
            [['action' => 'QueuePause', 'fields' => ['Interface' => 'PJSIP/203', 'Paused' => 'true', 'Reason' => 'Inactive']]],
            $this->amiActionSender->sentActions,
        );
    }

    public function testAnInactivePauseThePbxCouldNotTakeIsSentAgainUntilItIs(): void
    {
        $this->addAgent('203', null);
        $this->amiActionSender->failNextActionsWith(new AmiConnectionLost('Not logged in to the exchange.'), 2);
        $this->daemonServer->openWebSocket('/websocket?agent=203')->close();

        WebSocketTestClient::awaitCondition(fn(): bool => count($this->amiActionSender->sentActions) >= 3);
        delay(self::PING_INTERVAL_SECONDS * 4);

        self::assertSame(
            array_fill(0, 3, ['action' => 'QueuePause', 'fields' => ['Interface' => 'PJSIP/203', 'Paused' => 'true', 'Reason' => 'Inactive']]),
            $this->amiActionSender->sentActions,
        );
        self::assertCount(1, $this->logger->findRecords('Agent {extension} has no answering page, pausing as Inactive'));
    }

    public function testAnInactivePauseTheExchangeRefusesIsLoggedAndNotSentAgain(): void
    {
        $this->addAgent('203', null);
        $this->amiActionSender->failEveryActionWith(new AmiActionFailed('Interface not found'));
        $this->daemonServer->openWebSocket('/websocket?agent=203')->close();

        WebSocketTestClient::awaitCondition(fn(): bool => $this->logger->findRecords('The exchange did not confirm the Inactive pause of agent {extension}: {error}') !== []);
        delay(self::PING_INTERVAL_SECONDS * 4);

        self::assertCount(1, $this->amiActionSender->sentActions);
        self::assertCount(1, $this->logger->findRecords('The exchange did not confirm the Inactive pause of agent {extension}: {error}'));
    }

    public function testStoppingTheHeartbeatCancelsAPendingGraceSoTheShutdownPausesNobody(): void
    {
        $this->addAgent('203', null);
        $this->daemonServer->openWebSocket('/websocket?agent=203')->close();
        delay(self::PING_INTERVAL_SECONDS);

        $this->daemonServer->heartbeat->stop();
        delay(self::AGENT_GRACE_SECONDS * 2);

        self::assertSame([], $this->amiActionSender->sentActions);
    }

    public function testAReloadWithinTheGracePausesNobody(): void
    {
        $this->restartWithAgentGrace(self::LONG_AGENT_GRACE_SECONDS);
        $this->addAgent('203', null);
        $this->daemonServer->openWebSocket('/websocket?agent=203')->close();
        $reloadedPageClient = $this->daemonServer->openWebSocket('/websocket?agent=203');

        delay(self::LONG_AGENT_GRACE_SECONDS * 1.5);

        self::assertSame([], $this->amiActionSender->sentActions);
        self::assertFalse($reloadedPageClient->isClosed);
        self::assertGreaterThan(0, $reloadedPageClient->receivedPingCount);
    }

    public function testClosingOneOfTwoPagesPausesNobody(): void
    {
        $this->addAgent('203', null);
        $firstPageClient = $this->daemonServer->openWebSocket('/websocket?agent=203');
        $this->daemonServer->openWebSocket('/websocket?agent=203');

        $firstPageClient->close();
        delay(self::QUIET_PERIOD_SECONDS);

        self::assertSame([], $this->amiActionSender->sentActions);
        self::assertCount(1, $this->daemonServer->webSocketServer->listConnections());
    }

    public function testASilentPageBesideAnAnsweringPagePausesNobody(): void
    {
        $this->addAgent('203', null);
        $silentPageClient = $this->daemonServer->openWebSocket('/websocket?agent=203');
        $answeringPageClient = $this->daemonServer->openWebSocket('/websocket?agent=203');
        $silentPageClient->stopAnsweringPings();

        WebSocketTestClient::awaitCondition(fn(): bool => $silentPageClient->isClosed);

        self::assertTrue($silentPageClient->isClosed);
        self::assertSame([], $this->amiActionSender->sentActions);
        self::assertFalse($answeringPageClient->isClosed);
        self::assertCount(1, $this->daemonServer->webSocketServer->listConnections());
    }

    public function testTheWallboardIsClosedWhenSilentButPausesNobody(): void
    {
        $this->addAgent('203', null);
        $wallboardClient = $this->daemonServer->openWebSocket('/websocket');
        $wallboardClient->stopAnsweringPings();

        WebSocketTestClient::awaitCondition(fn(): bool => $wallboardClient->isClosed);
        delay(self::QUIET_PERIOD_SECONDS);

        self::assertTrue($wallboardClient->isClosed);
        self::assertSame(Frame::CLOSE_NORMAL, $wallboardClient->receivedCloseCode);
        self::assertSame([], $this->daemonServer->webSocketServer->listConnections());
        self::assertSame([], $this->amiActionSender->sentActions);
    }

    public function testAnAgentAlreadyOnLunchKeepsLunch(): void
    {
        $this->addAgent('203', PauseReason::Lunch);
        $agentPageClient = $this->daemonServer->openWebSocket('/websocket?agent=203');
        $agentPageClient->stopAnsweringPings();

        WebSocketTestClient::awaitCondition(fn(): bool => $agentPageClient->isClosed);
        delay(self::QUIET_PERIOD_SECONDS);

        self::assertTrue($agentPageClient->isClosed);
        self::assertSame([], $this->amiActionSender->sentActions);
    }

    public function testAnAgentWithoutAnyPageIsNeverPaused(): void
    {
        $this->addAgent('201', null);
        $this->addAgent('203', null);
        $agentPageClient = $this->daemonServer->openWebSocket('/websocket?agent=201');

        delay(self::QUIET_PERIOD_SECONDS);

        self::assertSame([], $this->amiActionSender->sentActions);
        self::assertFalse($agentPageClient->isClosed);
        self::assertGreaterThan(0, $agentPageClient->receivedPingCount);
    }

    public function testAPageForAnAgentTheFloorDoesNotKnowPausesNobody(): void
    {
        $agentPageClient = $this->daemonServer->openWebSocket('/websocket?agent=203');
        $agentPageClient->awaitMessages(1);

        $agentPageClient->close();
        delay(self::QUIET_PERIOD_SECONDS);

        self::assertSame([], $this->amiActionSender->sentActions);
    }

    public function testAPongInTheSameChunkAfterACloseDoesNotCancelTheGrace(): void
    {
        $this->addAgent('203', null);
        $agentPageClient = $this->daemonServer->openWebSocket('/websocket?agent=203');
        $agentPageClient->awaitMessages(1);

        $agentPageClient->sendFramesInOneChunk(
            new Frame(pack('n', Frame::CLOSE_GOING_AWAY), true, Frame::OP_CLOSE),
            new Frame(json_encode(['action' => 'pong'], JSON_THROW_ON_ERROR), true, Frame::OP_TEXT),
        );
        WebSocketTestClient::awaitCondition(fn(): bool => $this->amiActionSender->sentActions !== []);

        self::assertSame('Inactive', $this->amiActionSender->sentActions[0]['fields']['Reason'] ?? null);
        self::assertSame([], $this->daemonServer->webSocketServer->listConnections());
    }

    private function createDaemonServer(float $agentGraceSeconds): DaemonServerHarness
    {
        return new DaemonServerHarness(
            $this->floor,
            $this->amiActionSender,
            new DaemonSettings(
                pingIntervalSeconds: self::PING_INTERVAL_SECONDS,
                pongTimeoutSeconds: self::PONG_TIMEOUT_SECONDS,
                agentGraceSeconds: $agentGraceSeconds,
                amiReplyTimeoutSeconds: 1.0,
                resyncTimeoutSeconds: 1.0,
                amiKeepaliveIntervalSeconds: 60.0,
                longPollSeconds: 1.0,
                longPollHistoryLength: 10,
            ),
            $this->clock,
            $this->logger,
        );
    }

    private function restartWithAgentGrace(float $agentGraceSeconds): void
    {
        $this->daemonServer->close();
        $this->daemonServer = $this->createDaemonServer($agentGraceSeconds);
    }

    private function addAgent(string $extension, ?PauseReason $pauseReason): void
    {
        $this->floor->addAgent(new Agent(
            $extension,
            'Agent ' . $extension,
            ['sales', 'support'],
            $this->clock->now(),
            ExtensionStatus::Idle,
            $pauseReason,
            $pauseReason === null ? null : $this->clock->now(),
            null,
            $this->clock->now(),
        ));
    }
}
