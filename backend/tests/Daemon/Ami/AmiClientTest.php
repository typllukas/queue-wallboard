<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Daemon\Ami;

use Closure;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueueWallboard\Ami\AmiPacket;
use QueueWallboard\Daemon\Ami\AmiActionFailed;
use QueueWallboard\Daemon\Ami\AmiActionTimedOut;
use QueueWallboard\Daemon\Ami\AmiClient;
use QueueWallboard\Daemon\Ami\AmiConnectionLost;
use QueueWallboard\Daemon\Ami\AmiEventHandler;
use QueueWallboard\Daemon\Ami\QueueStatusResync;
use QueueWallboard\Daemon\Floor\FloorState;
use QueueWallboard\Daemon\Floor\PauseReason;
use QueueWallboard\Daemon\Floor\QueueSettings;
use QueueWallboard\Simulator\AmiServer;
use QueueWallboard\Simulator\CallCentre;
use QueueWallboard\Simulator\Floor;
use QueueWallboard\Simulator\SimulationTimings;
use Random\Engine\Mt19937;
use Random\Randomizer;
use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use Symfony\Component\Clock\MockClock;
use Throwable;

use function React\Async\await;
use function React\Async\delay;

#[CoversClass(AmiClient::class)]
#[CoversClass(QueueStatusResync::class)]
final class AmiClientTest extends TestCase
{
    private const string USERNAME = 'demo';
    private const string SECRET = 'demo-secret';
    private const float REPLY_TIMEOUT_SECONDS = 1.0;
    private const float SHORT_REPLY_TIMEOUT_SECONDS = 0.1;
    private const float RESYNC_TIMEOUT_SECONDS = 2.0;
    private const float SHORT_RESYNC_TIMEOUT_SECONDS = 0.1;
    private const float KEEPALIVE_INTERVAL_SECONDS = 60.0;
    private const float SHORT_KEEPALIVE_INTERVAL_SECONDS = 0.1;
    private const array RECONNECT_DELAYS_SECONDS = [0.05, 0.1, 0.2];
    private const float WAIT_STEP_SECONDS = 0.01;
    private const float WAIT_LIMIT_SECONDS = 3.0;
    private const string RECONNECT_LOG_MESSAGE = 'Reconnecting to the exchange in {delaySeconds} s';

    private MockClock $clock;
    private FloorState $floor;
    private RecordingLogger $logger;
    private ?AmiClient $client = null;

    /** @var list<AmiServer|ScriptedAmiServer> */
    private array $servers = [];

    /** @var list<CallCentre> */
    private array $callCentres = [];

    #[Override]
    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-03 10:00:00 Europe/Prague');
        $this->floor = new FloorState(QueueSettings::createDemoSettings(), $this->clock);
        $this->logger = new RecordingLogger();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->client?->close();
        foreach ($this->servers as $server) {
            $server->close();
        }
        foreach ($this->callCentres as $callCentre) {
            $callCentre->stop();
        }
    }

    public function testRebuildsTheFloorAfterConnecting(): void
    {
        $callCentre = $this->createCallCentre();
        $callCentre->pauseMember('PJSIP/203', true, 'Lunch');
        $address = $this->startAmiServer('127.0.0.1:0', $callCentre);

        $this->connectClient($address, self::REPLY_TIMEOUT_SECONDS);
        $this->awaitCondition(fn(): bool => $this->floor->isExchangeConnected());

        $pausedAgent = $this->floor->findAgent('203');
        self::assertNotNull($pausedAgent);
        self::assertSame(PauseReason::Lunch, $pausedAgent->pauseReason);
        self::assertSame(['sales', 'support'], $pausedAgent->queueNames);
        self::assertCount(6, $this->floor->listAgents());
        self::assertCount(1, $this->logger->findRecords('Resync complete: {agentCount} agents, {waitingCallCount} waiting calls, {replayedEventCount} replayed events'));
        self::assertStringNotContainsString(self::SECRET, json_encode($this->logger->records, JSON_THROW_ON_ERROR));
    }

    public function testReconnectsAfterTheExchangeRestarts(): void
    {
        $exchangeConnectionHistory = [];
        $address = $this->startAmiServer('127.0.0.1:0', $this->createCallCentre());
        $client = $this->connectClient($address, self::REPLY_TIMEOUT_SECONDS);
        $client->registerFloorChangeListener(function () use (&$exchangeConnectionHistory): void {
            if ($this->floor->takeChanges()->isExchangeConnectionChanged) {
                $exchangeConnectionHistory[] = $this->floor->isExchangeConnected();
            }
        });
        $this->awaitCondition(fn(): bool => $this->floor->isExchangeConnected());

        array_shift($this->servers)?->close();
        $restartedCallCentre = $this->createCallCentre();
        $restartedCallCentre->pauseMember('PJSIP/205', true, 'Training');
        $this->startAmiServer($address, $restartedCallCentre);
        $this->awaitCondition(fn(): bool => $this->floor->findAgent('205')?->pauseReason === PauseReason::Training);

        self::assertTrue($this->floor->isExchangeConnected());
        self::assertSame([true, false, true], $exchangeConnectionHistory);
    }

    public function testSendActionResolvesWithTheMatchingResponse(): void
    {
        $address = $this->startAmiServer('127.0.0.1:0', $this->createCallCentre());
        $client = $this->connectClient($address, self::REPLY_TIMEOUT_SECONDS);
        $this->awaitCondition(fn(): bool => $this->floor->isExchangeConnected());

        $pausePromise = $client->sendAction('QueuePause', ['Interface' => 'PJSIP/203', 'Paused' => 'true', 'Reason' => 'Lunch']);
        $unpausePromise = $client->sendAction('QueuePause', ['Interface' => 'PJSIP/205', 'Paused' => 'false']);
        $pauseReply = await($pausePromise);
        $unpauseReply = await($unpausePromise);

        self::assertSame('Interface paused successfully', $pauseReply->getField('Message'));
        self::assertSame('Interface unpaused successfully', $unpauseReply->getField('Message'));
        self::assertNotSame($pauseReply->findActionId(), $unpauseReply->findActionId());
    }

    public function testSendActionRejectsOnAnErrorResponse(): void
    {
        $address = $this->startAmiServer('127.0.0.1:0', $this->createCallCentre());
        $client = $this->connectClient($address, self::REPLY_TIMEOUT_SECONDS);
        $this->awaitCondition(fn(): bool => $this->floor->isExchangeConnected());

        $this->expectException(AmiActionFailed::class);
        $this->expectExceptionMessageIs('Interface not found');

        await($client->sendAction('QueuePause', ['Interface' => 'PJSIP/299', 'Paused' => 'true', 'Reason' => 'Lunch']));
    }

    public function testSendActionWithALineBreakInAFieldRejectsAndKeepsTheConnection(): void
    {
        $address = $this->startAmiServer('127.0.0.1:0', $this->createCallCentre());
        $client = $this->connectClient($address, self::REPLY_TIMEOUT_SECONDS);
        $this->awaitCondition(fn(): bool => $this->floor->isExchangeConnected());

        try {
            await($client->sendAction('QueuePause', ['Interface' => "PJSIP/203\r\n\r\nAction: Logoff", 'Paused' => 'false']));
            self::fail('An action with a line break in a field was sent.');
        } catch (AmiActionFailed $exception) {
            self::assertStringContainsString('line break', $exception->getMessage());
        }
        $unpauseReply = await($client->sendAction('QueuePause', ['Interface' => 'PJSIP/205', 'Paused' => 'false']));

        self::assertSame('Interface unpaused successfully', $unpauseReply->getField('Message'));
    }

    public function testSendActionTimesOut(): void
    {
        $address = $this->startScriptedServer(
            ScriptedAmiServer::writeBanner(...),
            static function (AmiPacket $actionPacket, ConnectionInterface $connection): void {
                match ($actionPacket->findField('Action')) {
                    'Login' => ScriptedAmiServer::writeSuccessReply($connection, $actionPacket, 'Authentication accepted'),
                    'QueueStatus' => ScriptedAmiServer::writeEmptyQueueStatus($connection, $actionPacket),
                    default => null,
                };
            },
        )->getAddress();
        $client = $this->connectClient($address, self::SHORT_REPLY_TIMEOUT_SECONDS);
        $this->awaitCondition(fn(): bool => $this->floor->isExchangeConnected());

        $this->expectException(AmiActionTimedOut::class);

        await($client->sendAction('Ping', []));
    }

    public function testSendActionWhileDisconnectedRejectsWithConnectionLost(): void
    {
        $client = $this->createClient('127.0.0.1:1', self::REPLY_TIMEOUT_SECONDS);
        $rejectionReason = null;

        $client->sendAction('Ping', [])->then(null, static function (Throwable $throwable) use (&$rejectionReason): void {
            $rejectionReason = $throwable;
        });

        self::assertInstanceOf(AmiConnectionLost::class, $rejectionReason);
    }

    public function testAMalformedPacketMakesItReconnect(): void
    {
        $scriptedServer = $this->startScriptedServer(
            ScriptedAmiServer::writeBanner(...),
            static function (AmiPacket $actionPacket, ConnectionInterface $connection): void {
                match ($actionPacket->findField('Action')) {
                    'Login' => ScriptedAmiServer::writeSuccessReply($connection, $actionPacket, 'Authentication accepted'),
                    default => $connection->write("Not an AMI line\r\n\r\n"),
                };
            },
        );

        $this->connectClient($scriptedServer->getAddress(), self::REPLY_TIMEOUT_SECONDS);
        $this->awaitCondition(static fn(): bool => $scriptedServer->connectionCount >= 2);

        self::assertGreaterThanOrEqual(2, $scriptedServer->connectionCount);
        self::assertNotSame([], $this->logger->findRecords('Malformed AMI packet: {error}'));
        self::assertFalse($this->floor->isExchangeConnected());
    }

    public function testAnExchangeThatStopsAnsweringTheKeepaliveIsDroppedAndReconnected(): void
    {
        $scriptedServer = $this->startScriptedServer(
            ScriptedAmiServer::writeBanner(...),
            static function (AmiPacket $actionPacket, ConnectionInterface $connection): void {
                match ($actionPacket->findField('Action')) {
                    'Login' => ScriptedAmiServer::writeSuccessReply($connection, $actionPacket, 'Authentication accepted'),
                    'QueueStatus' => ScriptedAmiServer::writeEmptyQueueStatus($connection, $actionPacket),
                    default => null,
                };
            },
        );

        $this->connectClient($scriptedServer->getAddress(), self::SHORT_REPLY_TIMEOUT_SECONDS, keepaliveIntervalSeconds: self::SHORT_KEEPALIVE_INTERVAL_SECONDS);
        $this->awaitCondition(static fn(): bool => $scriptedServer->connectionCount >= 2);

        self::assertGreaterThanOrEqual(2, $scriptedServer->connectionCount);
        self::assertContains('the exchange did not answer a keepalive Ping', $this->listDropReasons());
    }

    public function testAnExchangeThatAnswersTheKeepaliveStaysConnected(): void
    {
        $scriptedServer = $this->startScriptedServer(
            ScriptedAmiServer::writeBanner(...),
            static function (AmiPacket $actionPacket, ConnectionInterface $connection): void {
                match ($actionPacket->findField('Action')) {
                    'QueueStatus' => ScriptedAmiServer::writeEmptyQueueStatus($connection, $actionPacket),
                    default => ScriptedAmiServer::writeSuccessReply($connection, $actionPacket, 'Pong'),
                };
            },
        );

        $this->connectClient($scriptedServer->getAddress(), self::SHORT_REPLY_TIMEOUT_SECONDS, keepaliveIntervalSeconds: self::SHORT_KEEPALIVE_INTERVAL_SECONDS);
        $this->awaitCondition(fn(): bool => $this->floor->isExchangeConnected());
        delay(0.5);

        self::assertSame(1, $scriptedServer->connectionCount);
        self::assertTrue($this->floor->isExchangeConnected());
    }

    public function testALateReplyAfterTheTimeoutIsIgnored(): void
    {
        $address = $this->startScriptedServer(
            ScriptedAmiServer::writeBanner(...),
            static function (AmiPacket $actionPacket, ConnectionInterface $connection): void {
                match ($actionPacket->findField('Action')) {
                    'Login' => ScriptedAmiServer::writeSuccessReply($connection, $actionPacket, 'Authentication accepted'),
                    'QueueStatus' => ScriptedAmiServer::writeEmptyQueueStatus($connection, $actionPacket),
                    'SlowPing' => Loop::addTimer(0.2, static fn() => ScriptedAmiServer::writeSuccessReply($connection, $actionPacket, 'Late pong')),
                    default => ScriptedAmiServer::writeSuccessReply($connection, $actionPacket, 'Pong'),
                };
            },
        )->getAddress();
        $client = $this->connectClient($address, self::SHORT_REPLY_TIMEOUT_SECONDS);
        $this->awaitCondition(fn(): bool => $this->floor->isExchangeConnected());

        $slowPingFailure = null;
        try {
            await($client->sendAction('SlowPing', []));
        } catch (AmiActionTimedOut $exception) {
            $slowPingFailure = $exception;
        }
        $this->awaitCondition(fn(): bool => $this->logger->findRecords('Dropping an AMI reply for no pending action {actionId}') !== []);

        self::assertInstanceOf(AmiActionTimedOut::class, $slowPingFailure);
        self::assertSame('Pong', await($client->sendAction('Ping', []))->getField('Message'));
        self::assertTrue($this->floor->isExchangeConnected());
    }

    public function testADropMidPacketStillRebuildsAfterReconnecting(): void
    {
        $scriptedServer = $this->startScriptedServer(
            static fn(ConnectionInterface $connection) => $connection->end(ScriptedAmiServer::BANNER . "Event: QueueMemberPause\r\nQueue: sa"),
            static function (): void {},
        );
        $address = $scriptedServer->getAddress();
        $this->connectClient($address, self::REPLY_TIMEOUT_SECONDS);
        $this->awaitCondition(static fn(): bool => $scriptedServer->connectionCount >= 1);

        array_shift($this->servers)?->close();
        $this->startAmiServer($address, $this->createCallCentre());
        $this->awaitCondition(fn(): bool => $this->floor->isExchangeConnected());

        self::assertTrue($this->floor->isExchangeConnected());
        self::assertCount(6, $this->floor->listAgents());
    }

    public function testAServerThatNeverSendsTheBannerIsRetried(): void
    {
        $scriptedServer = $this->startScriptedServer(static function (): void {}, static function (): void {});

        $this->connectClient($scriptedServer->getAddress(), self::SHORT_REPLY_TIMEOUT_SECONDS);
        $this->awaitCondition(static fn(): bool => $scriptedServer->connectionCount >= 2);

        self::assertGreaterThanOrEqual(2, $scriptedServer->connectionCount);
        self::assertContains('no AMI banner within the reply timeout', $this->listDropReasons());
    }

    public function testRetryDelaysGrowToTheLastOneAndResetAfterALogin(): void
    {
        $addressProbe = new SocketServer('127.0.0.1:0');
        $address = $addressProbe->getAddress() ?? '';
        $addressProbe->close();

        $this->connectClient($address, self::REPLY_TIMEOUT_SECONDS);
        $this->awaitCondition(fn(): bool => count($this->logger->findRecords(self::RECONNECT_LOG_MESSAGE)) >= 4);
        $this->startAmiServer($address, $this->createCallCentre());
        $this->awaitCondition(fn(): bool => $this->floor->isExchangeConnected());
        $retryCountBeforeTheDrop = count($this->logger->findRecords(self::RECONNECT_LOG_MESSAGE));
        array_shift($this->servers)?->close();
        $this->awaitCondition(fn(): bool => count($this->logger->findRecords(self::RECONNECT_LOG_MESSAGE)) > $retryCountBeforeTheDrop);

        $retryDelays = array_map(
            static fn(array $record): mixed => $record['context']['delaySeconds'] ?? null,
            $this->logger->findRecords(self::RECONNECT_LOG_MESSAGE),
        );
        self::assertSame([0.05, 0.1, 0.2, 0.2], array_slice($retryDelays, 0, 4));
        self::assertSame(0.05, $retryDelays[$retryCountBeforeTheDrop]);
    }

    public function testAResyncThatNeverCompletesIsDroppedAndRetried(): void
    {
        $exchangeConnectionHistory = [];
        $scriptedServer = $this->startScriptedServer(
            ScriptedAmiServer::writeBanner(...),
            static function (AmiPacket $actionPacket, ConnectionInterface $connection): void {
                match ($actionPacket->findField('Action')) {
                    'Login' => ScriptedAmiServer::writeSuccessReply($connection, $actionPacket, 'Authentication accepted'),
                    default => ScriptedAmiServer::writeSuccessReply($connection, $actionPacket, 'Queue status will follow'),
                };
            },
        );
        $client = $this->connectClient($scriptedServer->getAddress(), self::REPLY_TIMEOUT_SECONDS, resyncTimeoutSeconds: self::SHORT_RESYNC_TIMEOUT_SECONDS);
        $client->registerFloorChangeListener(function () use (&$exchangeConnectionHistory): void {
            $exchangeConnectionHistory[] = $this->floor->isExchangeConnected();
        });
        $this->awaitCondition(static fn(): bool => $scriptedServer->connectionCount >= 2);

        self::assertGreaterThanOrEqual(2, $scriptedServer->connectionCount);
        self::assertContains('the QueueStatus resync did not complete in time', $this->listDropReasons());
        self::assertNotContains(true, $exchangeConnectionHistory);
    }

    public function testAPendingActionIsRejectedWithConnectionLostWhenTheConnectionDrops(): void
    {
        $scriptedServer = $this->startScriptedServer(
            ScriptedAmiServer::writeBanner(...),
            static function (AmiPacket $actionPacket, ConnectionInterface $connection): void {
                match ($actionPacket->findField('Action')) {
                    'Login' => ScriptedAmiServer::writeSuccessReply($connection, $actionPacket, 'Authentication accepted'),
                    'QueueStatus' => ScriptedAmiServer::writeEmptyQueueStatus($connection, $actionPacket),
                    default => $connection->close(),
                };
            },
        );
        $client = $this->connectClient($scriptedServer->getAddress(), self::REPLY_TIMEOUT_SECONDS);
        $this->awaitCondition(fn(): bool => $this->floor->isExchangeConnected());
        $isExchangeConnectedAtRejection = null;

        $actionPromise = $client->sendAction('Hangup', ['Channel' => 'PJSIP/203-00000001'])->catch(
            function (Throwable $throwable) use (&$isExchangeConnectedAtRejection): Throwable {
                $isExchangeConnectedAtRejection = $this->floor->isExchangeConnected();

                return $throwable;
            },
        );

        self::assertInstanceOf(AmiConnectionLost::class, await($actionPromise));
        self::assertFalse($isExchangeConnectedAtRejection);
    }

    public function testALoginWithoutAReplyIsLoggedAsATimeoutNotARefusal(): void
    {
        $scriptedServer = $this->startScriptedServer(ScriptedAmiServer::writeBanner(...), static function (): void {});

        $this->connectClient($scriptedServer->getAddress(), self::SHORT_REPLY_TIMEOUT_SECONDS);
        $this->awaitCondition(fn(): bool => $this->logger->findRecords('The exchange did not answer the login in time') !== []);

        self::assertCount(1, $this->logger->findRecords('The exchange did not answer the login in time'));
        self::assertSame([], $this->logger->findRecords('The exchange refused the login: {error}'));
    }

    private function createCallCentre(): CallCentre
    {
        $callCentre = new CallCentre(
            Floor::createDemoFloor(),
            SimulationTimings::createDemoTimings(),
            Loop::get(),
            new Randomizer(new Mt19937(1)),
            new MockClock('2026-11-03 10:00:00 Europe/Prague'),
        );
        $this->callCentres[] = $callCentre;

        return $callCentre;
    }

    private function startAmiServer(string $address, CallCentre $callCentre): string
    {
        $socketServer = new SocketServer($address);
        $this->servers[] = new AmiServer($socketServer, $callCentre, self::USERNAME, self::SECRET);

        return $socketServer->getAddress() ?? '';
    }

    /**
     * @param Closure(ConnectionInterface): void $greetConnection
     * @param Closure(AmiPacket, ConnectionInterface): void $answerAction
     */
    private function startScriptedServer(Closure $greetConnection, Closure $answerAction): ScriptedAmiServer
    {
        $scriptedServer = new ScriptedAmiServer('127.0.0.1:0', $greetConnection, $answerAction);
        $this->servers[] = $scriptedServer;

        return $scriptedServer;
    }

    private function createClient(
        string $address,
        float $replyTimeoutSeconds,
        float $resyncTimeoutSeconds = self::RESYNC_TIMEOUT_SECONDS,
        float $keepaliveIntervalSeconds = self::KEEPALIVE_INTERVAL_SECONDS,
    ): AmiClient {
        $eventHandler = new AmiEventHandler($this->floor, $this->clock);
        $this->client = new AmiClient(
            $address,
            self::USERNAME,
            self::SECRET,
            Loop::get(),
            new QueueStatusResync($this->floor, $eventHandler, $this->clock, $this->logger),
            $eventHandler,
            $this->logger,
            $replyTimeoutSeconds,
            $resyncTimeoutSeconds,
            $keepaliveIntervalSeconds,
            self::RECONNECT_DELAYS_SECONDS,
        );

        return $this->client;
    }

    private function connectClient(
        string $address,
        float $replyTimeoutSeconds,
        float $resyncTimeoutSeconds = self::RESYNC_TIMEOUT_SECONDS,
        float $keepaliveIntervalSeconds = self::KEEPALIVE_INTERVAL_SECONDS,
    ): AmiClient {
        $client = $this->createClient($address, $replyTimeoutSeconds, $resyncTimeoutSeconds, $keepaliveIntervalSeconds);
        $client->connect();

        return $client;
    }

    /**
     * @return list<mixed>
     */
    private function listDropReasons(): array
    {
        return array_map(
            static fn(array $record): mixed => $record['context']['reason'] ?? null,
            $this->logger->findRecords('AMI connection dropped: {reason}'),
        );
    }

    /**
     * @param callable(): bool $isMet
     */
    private function awaitCondition(callable $isMet): void
    {
        for ($waitedSeconds = 0.0; $waitedSeconds < self::WAIT_LIMIT_SECONDS && !$isMet(); $waitedSeconds += self::WAIT_STEP_SECONDS) {
            delay(self::WAIT_STEP_SECONDS);
        }
    }
}
