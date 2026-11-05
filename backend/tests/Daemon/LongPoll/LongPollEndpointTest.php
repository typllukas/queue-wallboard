<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Daemon\LongPoll;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use QueueWallboard\Daemon\Floor\Agent;
use QueueWallboard\Daemon\Floor\ExtensionStatus;
use QueueWallboard\Daemon\Floor\FloorState;
use QueueWallboard\Daemon\Floor\PauseReason;
use QueueWallboard\Daemon\Floor\QueueSettings;
use QueueWallboard\Daemon\LongPoll\ChangeHistory;
use QueueWallboard\Daemon\LongPoll\LongPollEndpoint;
use QueueWallboard\Daemon\Wire\WireMapper;
use QueueWallboard\Tests\Daemon\TimerCountingLoop;
use React\Http\Browser;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;
use React\Http\Middleware\LimitConcurrentRequestsMiddleware;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Socket\SocketServer;
use Symfony\Component\Clock\MockClock;
use Throwable;

use function React\Async\await;
use function React\Async\delay;

#[CoversClass(LongPollEndpoint::class)]
final class LongPollEndpointTest extends TestCase
{
    private const string BOOT_ID = '5f0c2a9e';
    private const float LONG_POLL_SECONDS = 0.1;
    private const float DISCONNECT_SETTLE_SECONDS = 0.02;
    private const float LATER_PULL_TIMEOUT_SECONDS = 1.0;
    private const int CONCURRENCY_LIMIT = 1;
    private const int DISCONNECTED_PULL_COUNT = 3;

    private MockClock $clock;
    private FloorState $floor;
    private TimerCountingLoop $loop;
    private LongPollEndpoint $endpoint;
    private ?SocketServer $httpSocketServer = null;

    /** @var list<PromiseInterface<ResponseInterface>> */
    private array $pullPromises = [];

    /** @var list<ConnectionInterface> */
    private array $clientConnections = [];

    #[Override]
    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-03 10:00:00 Europe/Prague');
        $this->floor = new FloorState(QueueSettings::createDemoSettings(), $this->clock);
        $this->floor->addAgent($this->createAgent('203'));
        $this->floor->updateExchangeConnection(true);
        $this->floor->takeChanges();
        $this->loop = new TimerCountingLoop();
        $this->endpoint = new LongPollEndpoint(
            $this->loop,
            new ChangeHistory(self::BOOT_ID, 3),
            new WireMapper($this->floor),
            self::LONG_POLL_SECONDS,
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->pullPromises as $pullPromise) {
            $pullPromise->cancel();
        }
        foreach ($this->clientConnections as $clientConnection) {
            $clientConnection->close();
        }
        $this->httpSocketServer?->close();
    }

    public function testNoHashAnswersTheFullStateAtOnce(): void
    {
        $responseBody = $this->findSettledResponseBody($this->pull('/pull'));

        self::assertSame('5f0c2a9e-0', $responseBody['hash'] ?? null);
        self::assertTrue($responseBody['full'] ?? null);
        self::assertSame(['203'], $this->listAgentValues($responseBody, 'exten'));
        self::assertSame(['sales', 'support'], $this->listQueueNames($responseBody));
        self::assertTrue($responseBody['exchange_connected'] ?? null);
    }

    public function testAnAnswerForbidsCaching(): void
    {
        self::assertSame('no-store', $this->findSettledResponse($this->pull('/pull'))?->getHeaderLine('Cache-Control'));
    }

    public function testAHashFromBeforeARestartAnswersTheFullStateAtOnce(): void
    {
        $responseBody = $this->findSettledResponseBody($this->pull('/pull?hash=0d1e2f3a-0'));

        self::assertSame('5f0c2a9e-0', $responseBody['hash'] ?? null);
        self::assertTrue($responseBody['full'] ?? null);
        self::assertSame(['203'], $this->listAgentValues($responseBody, 'exten'));
    }

    public function testAKnownHashWithChangesSinceAnswersTheChangedEntitiesAtOnce(): void
    {
        $this->pauseAgentForLunch();

        $responseBody = $this->findSettledResponseBody($this->pull('/pull?hash=5f0c2a9e-0'));

        self::assertSame('5f0c2a9e-1', $responseBody['hash'] ?? null);
        self::assertFalse($responseBody['full'] ?? null);
        self::assertSame(['203'], $this->listAgentValues($responseBody, 'exten'));
        self::assertSame(['lunch'], $this->listAgentValues($responseBody, 'id_pause'));
        self::assertSame(['sales', 'support'], $this->listQueueNames($responseBody));
    }

    public function testAHeldRequestIsAnsweredByTheNextChange(): void
    {
        $pullPromise = $this->pull('/pull?hash=5f0c2a9e-0');
        self::assertNull($this->findSettledResponse($pullPromise));

        $this->pauseAgentForLunch();

        $responseBody = $this->findSettledResponseBody($pullPromise);
        self::assertSame('5f0c2a9e-1', $responseBody['hash'] ?? null);
        self::assertFalse($responseBody['full'] ?? null);
        self::assertSame(['203'], $this->listAgentValues($responseBody, 'exten'));
        self::assertSame(0, $this->loop->countPendingTimers());
    }

    public function testOneChangeAnswersEveryHeldRequest(): void
    {
        $firstPullPromise = $this->pull('/pull?hash=5f0c2a9e-0');
        $secondPullPromise = $this->pull('/pull?hash=5f0c2a9e-0');
        self::assertSame(2, $this->loop->countPendingTimers());

        $this->pauseAgentForLunch();

        $firstResponseBody = $this->findSettledResponseBody($firstPullPromise);
        self::assertSame('5f0c2a9e-1', $firstResponseBody['hash'] ?? null);
        self::assertSame(['lunch'], $this->listAgentValues($firstResponseBody, 'id_pause'));
        self::assertSame($firstResponseBody, $this->findSettledResponseBody($secondPullPromise));
        self::assertSame(0, $this->loop->countPendingTimers());
    }

    public function testAFlushWithoutChangesKeepsTheRequestHeld(): void
    {
        $pullPromise = $this->pull('/pull?hash=5f0c2a9e-0');

        $this->endpoint->publishChanges($this->floor->takeChanges());

        self::assertNull($this->findSettledResponse($pullPromise));
    }

    public function testAHeldRequestTimesOutWithTheSameHash(): void
    {
        $startedAt = microtime(true);

        $response = await($this->pull('/pull?hash=5f0c2a9e-0'));

        self::assertGreaterThanOrEqual(self::LONG_POLL_SECONDS, microtime(true) - $startedAt);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            '{"hash":"5f0c2a9e-0","full":false,"agents":[],"queues":[],"exchange_connected":true}',
            strval($response->getBody()),
        );
    }

    public function testACancelledRequestLeavesNoTimer(): void
    {
        $pullPromise = $this->pull('/pull?hash=5f0c2a9e-0');
        self::assertSame(1, $this->loop->countPendingTimers());

        $pullPromise->cancel();
        $this->pauseAgentForLunch();

        self::assertSame(0, $this->loop->countPendingTimers());
        self::assertSame(Response::STATUS_NO_CONTENT, $this->findSettledResponse($pullPromise)?->getStatusCode());
    }

    public function testShuttingDownAnswersHeldPullsAsUnavailableAndPublishesNothingToThem(): void
    {
        $heldPullPromise = $this->pull('/pull?hash=5f0c2a9e-0');

        $this->endpoint->refuseHeldRequests();
        $this->pauseAgentForLunch();

        self::assertSame(503, await($heldPullPromise)->getStatusCode());
        self::assertSame(0, $this->loop->countPendingTimers());
    }

    public function testDisconnectedPullsGiveBackTheirConcurrencySlot(): void
    {
        $this->httpSocketServer = new SocketServer('127.0.0.1:0');
        new HttpServer(new LimitConcurrentRequestsMiddleware(self::CONCURRENCY_LIMIT), $this->endpoint->handlePull(...))->listen($this->httpSocketServer);
        $address = $this->httpSocketServer->getAddress() ?? '';

        for ($disconnectedPullIndex = 0; $disconnectedPullIndex < self::DISCONNECTED_PULL_COUNT; $disconnectedPullIndex++) {
            $clientConnection = await(new Connector()->connect($address));
            $this->clientConnections[] = $clientConnection;
            $clientConnection->write("GET /pull?hash=5f0c2a9e-0 HTTP/1.1\r\nHost: localhost\r\n\r\n");
            delay(self::DISCONNECT_SETTLE_SECONDS);
            self::assertSame(1, $this->loop->countPendingTimers());
            $clientConnection->close();
            delay(self::DISCONNECT_SETTLE_SECONDS);
        }
        $laterPullPromise = new Browser()->withTimeout(self::LATER_PULL_TIMEOUT_SECONDS)->get(str_replace('tcp://', 'http://', $address) . '/pull');

        self::assertSame(200, await($laterPullPromise)->getStatusCode());
        self::assertSame(0, $this->loop->countPendingTimers());
    }

    public function testAReplacedLiveStateAnswersOlderHashesWithTheFullState(): void
    {
        $heldPullPromise = $this->pull('/pull?hash=5f0c2a9e-0');
        $this->floor->addAgent($this->createAgent('205'));
        $this->endpoint->publishChanges($this->floor->takeChanges());
        self::assertSame(['205'], $this->listAgentValues($this->findSettledResponseBody($heldPullPromise), 'exten'));
        $resyncHeldPullPromise = $this->pull('/pull?hash=5f0c2a9e-1');

        $this->floor->replaceLiveState([$this->createAgent('203')], [], []);
        $this->endpoint->publishChanges($this->floor->takeChanges());

        foreach ([$resyncHeldPullPromise, $this->pull('/pull?hash=5f0c2a9e-0'), $this->pull('/pull?hash=5f0c2a9e-1')] as $pullPromise) {
            $responseBody = $this->findSettledResponseBody($pullPromise);
            self::assertSame('5f0c2a9e-2', $responseBody['hash'] ?? null);
            self::assertTrue($responseBody['full'] ?? null);
            self::assertSame(['203'], $this->listAgentValues($responseBody, 'exten'));
        }
    }

    /**
     * @return PromiseInterface<ResponseInterface>
     */
    private function pull(string $pathAndQuery): PromiseInterface
    {
        $pullPromise = $this->endpoint->handlePull(new ServerRequest('GET', 'http://localhost' . $pathAndQuery));
        $this->pullPromises[] = $pullPromise;

        return $pullPromise;
    }

    private function pauseAgentForLunch(): void
    {
        $this->floor->updatePause('PJSIP/203', PauseReason::Lunch);
        $this->endpoint->publishChanges($this->floor->takeChanges());
    }

    private function createAgent(string $extension): Agent
    {
        return new Agent($extension, 'Agent ' . $extension, ['sales', 'support'], $this->clock->now(), ExtensionStatus::Idle, null, null, null, $this->clock->now());
    }

    /**
     * @param PromiseInterface<ResponseInterface> $pullPromise
     */
    private function findSettledResponse(PromiseInterface $pullPromise): ?ResponseInterface
    {
        $settledResponse = null;
        $rejection = null;
        $pullPromise->then(
            static function (ResponseInterface $response) use (&$settledResponse): void {
                $settledResponse = $response;
            },
            static function (Throwable $throwable) use (&$rejection): void {
                $rejection = $throwable;
            },
        );
        if ($rejection instanceof Throwable) {
            throw $rejection;
        }

        return $settledResponse;
    }

    /**
     * @param PromiseInterface<ResponseInterface> $pullPromise
     *
     * @return array<mixed>|null
     */
    private function findSettledResponseBody(PromiseInterface $pullPromise): ?array
    {
        $response = $this->findSettledResponse($pullPromise);
        $responseBody = $response === null ? null : json_decode(strval($response->getBody()), true, 512, JSON_THROW_ON_ERROR);

        return is_array($responseBody) ? $responseBody : null;
    }

    /**
     * @param array<mixed>|null $responseBody
     *
     * @return list<mixed>
     */
    private function listAgentValues(?array $responseBody, string $fieldName): array
    {
        return array_column(is_array($responseBody['agents'] ?? null) ? $responseBody['agents'] : [], $fieldName);
    }

    /**
     * @param array<mixed>|null $responseBody
     *
     * @return list<mixed>
     */
    private function listQueueNames(?array $responseBody): array
    {
        return array_column(is_array($responseBody['queues'] ?? null) ? $responseBody['queues'] : [], 'name');
    }
}
