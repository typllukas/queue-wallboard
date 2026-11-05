<?php

declare(strict_types=1);

namespace QueueWallboard\Daemon\Ami;

use Closure;
use Override;
use Psr\Log\LoggerInterface;
use QueueWallboard\Ami\AmiPacket;
use QueueWallboard\Ami\AmiPacketParser;
use QueueWallboard\Ami\MalformedAmiPacket;
use React\Dns\Config\Config;
use React\Dns\Resolver\Factory;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use SensitiveParameter;
use Throwable;

use function React\Promise\reject;

final class AmiClient implements AmiActionSender
{
    private const string BANNER_PREFIX = 'Asterisk Call Manager/';
    /** arbitrary; a refused connection fails at once, this only ends waiting on a host that does not answer */
    private const float CONNECT_TIMEOUT_SECONDS = 5.0;
    /** no jitter: one daemon per exchange, so no other client reconnects at the same moment */
    private const array RECONNECT_DELAYS_SECONDS = [0.5, 1.0, 2.0, 4.0, 8.0, 10.0];

    private readonly Connector $connector;
    private AmiPacketParser $parser;
    private ?ConnectionInterface $connection = null;
    private ?string $unreadBanner = null;
    private bool $isLoggedIn = false;
    private bool $isClosed = false;
    private int $actionCount = 0;
    private int $reconnectAttemptCount = 0;
    private ?TimerInterface $bannerTimer = null;
    private ?TimerInterface $resyncTimer = null;
    private ?TimerInterface $reconnectTimer = null;
    private ?TimerInterface $keepaliveTimer = null;
    private ?Closure $flushChanges = null;

    /** @var PromiseInterface<ConnectionInterface>|null */
    private ?PromiseInterface $connectionAttempt = null;

    /** @var array<int|string, array{action: string, deferred: Deferred<AmiPacket>, timer: TimerInterface}> */
    private array $pendingActions = [];

    /**
     * @param non-empty-list<float> $reconnectDelaysSeconds
     */
    public function __construct(
        private readonly string $address,
        private readonly string $username,
        #[SensitiveParameter]
        private readonly string $secret,
        private readonly LoopInterface $loop,
        private readonly QueueStatusResync $resync,
        private readonly AmiEventHandler $eventHandler,
        private readonly LoggerInterface $logger,
        private readonly float $replyTimeoutSeconds,
        private readonly float $resyncTimeoutSeconds,
        private readonly float $keepaliveIntervalSeconds,
        private readonly array $reconnectDelaysSeconds = self::RECONNECT_DELAYS_SECONDS,
    ) {
        // uncached: react/dns caches a failed lookup for 60 s, and the exchange's name does not resolve while its container restarts
        $uncachedResolver = new Factory()->create(Config::loadSystemConfigBlocking(), $loop);
        $this->connector = new Connector(['timeout' => self::CONNECT_TIMEOUT_SECONDS, 'dns' => $uncachedResolver], $loop);
        $this->parser = new AmiPacketParser();
    }

    public function connect(): void
    {
        $this->reconnectTimer = null;
        $this->parser = new AmiPacketParser();
        $this->unreadBanner = '';
        $this->connectionAttempt = $this->connector->connect($this->address);
        $this->connectionAttempt->then(
            $this->startSession(...),
            function (Throwable $throwable): void {
                $this->connectionAttempt = null;
                if ($this->isClosed) {
                    return;
                }
                $this->logger->warning('Connecting to the exchange at {address} failed: {error}', [
                    'address' => $this->address,
                    'error' => $throwable->getMessage(),
                ]);
                $this->scheduleReconnect();
            },
        );
    }

    public function close(): void
    {
        $this->isClosed = true;
        if ($this->reconnectTimer !== null) {
            $this->loop->cancelTimer($this->reconnectTimer);
            $this->reconnectTimer = null;
        }
        $this->connectionAttempt?->cancel();
        if ($this->connection !== null) {
            $this->dropConnection($this->connection, 'the client was closed');
        }
    }

    /**
     * @param callable(): void $flushChanges
     */
    public function registerFloorChangeListener(callable $flushChanges): void
    {
        $this->flushChanges = $flushChanges(...);
    }

    #[Override]
    public function sendAction(string $action, array $fields): PromiseInterface
    {
        if ($this->connection === null || !$this->isLoggedIn) {
            return reject(new AmiConnectionLost(sprintf('AMI action %s not sent: not logged in to the exchange.', $action)));
        }

        return $this->writeAction($this->connection, strval(++$this->actionCount), $action, $fields);
    }

    private function startSession(ConnectionInterface $connection): void
    {
        $this->connectionAttempt = null;
        if ($this->isClosed) {
            $connection->close();

            return;
        }
        $this->connection = $connection;
        $this->logger->info('Connected to the exchange at {address}', ['address' => $this->address]);
        $this->bannerTimer = $this->loop->addTimer(
            $this->replyTimeoutSeconds,
            fn() => $this->dropConnection($connection, 'no AMI banner within the reply timeout'),
        );
        $connection->on('data', fn(string $chunk) => $this->readChunk($connection, $chunk));
        $connection->on('error', fn(Throwable $throwable) => $this->dropConnection($connection, $throwable->getMessage()));
        $connection->on('close', fn() => $this->dropConnection($connection, 'closed by the exchange'));
    }

    private function readChunk(ConnectionInterface $connection, string $chunk): void
    {
        if ($this->unreadBanner !== null) {
            $this->unreadBanner .= $chunk;
            $bannerEnd = strpos($this->unreadBanner, "\r\n");
            if ($bannerEnd === false) {
                return;
            }
            if (!str_starts_with($this->unreadBanner, self::BANNER_PREFIX)) {
                $this->dropConnection($connection, 'the server did not greet with an AMI banner');

                return;
            }
            $chunk = substr($this->unreadBanner, $bannerEnd + 2);
            $this->unreadBanner = null;
            if ($this->bannerTimer !== null) {
                $this->loop->cancelTimer($this->bannerTimer);
                $this->bannerTimer = null;
            }
            $this->logIn($connection);
        }

        try {
            $packets = $this->parser->pushChunk($chunk);
        } catch (MalformedAmiPacket $exception) {
            $this->logger->error('Malformed AMI packet: {error}', ['error' => $exception->getMessage()]);
            $this->dropConnection($connection, 'malformed AMI packet');

            return;
        }
        foreach ($packets as $packet) {
            if ($connection !== $this->connection) {
                return;
            }
            try {
                $this->handlePacket($packet);
            } catch (Throwable $throwable) {
                $this->logger->error('Handling an AMI packet failed ({eventName}): {error}', [
                    'eventName' => $packet->findEventName() ?? $packet->findField('Response'),
                    'error' => $throwable->getMessage(),
                ]);
            }
        }
        $this->flushFloorChanges();
    }

    private function logIn(ConnectionInterface $connection): void
    {
        $this->writeAction($connection, strval(++$this->actionCount), 'Login', [
            'Username' => $this->username,
            'Secret' => $this->secret,
        ])->then(
            function () use ($connection): void {
                $this->isLoggedIn = true;
                $this->reconnectAttemptCount = 0;
                $this->logger->info('Logged in to the exchange as {username}', ['username' => $this->username]);
                $this->keepaliveTimer = $this->loop->addPeriodicTimer($this->keepaliveIntervalSeconds, fn() => $this->sendKeepalive($connection));
                $this->startResync($connection);
            },
            function (Throwable $throwable) use ($connection): void {
                if ($throwable instanceof AmiConnectionLost) {
                    return;
                }
                if ($throwable instanceof AmiActionTimedOut) {
                    $this->logger->error('The exchange did not answer the login in time');
                    $this->dropConnection($connection, 'login timed out');

                    return;
                }
                $this->logger->error('The exchange refused the login: {error}', ['error' => $throwable->getMessage()]);
                $this->dropConnection($connection, 'login refused');
            },
        );
    }

    private function sendKeepalive(ConnectionInterface $connection): void
    {
        $this->writeAction($connection, strval(++$this->actionCount), 'Ping', [])->catch(function (Throwable $throwable) use ($connection): void {
            if ($throwable instanceof AmiActionTimedOut) {
                $this->dropConnection($connection, 'the exchange did not answer a keepalive Ping');
            }
        });
    }

    private function startResync(ConnectionInterface $connection): void
    {
        $actionId = strval(++$this->actionCount);
        $this->resync->begin($actionId);
        $this->resyncTimer = $this->loop->addTimer($this->resyncTimeoutSeconds, function () use ($connection): void {
            $this->resyncTimer = null;
            if ($this->resync->isListing()) {
                $this->dropConnection($connection, 'the QueueStatus resync did not complete in time');
            }
        });
        $this->writeAction($connection, $actionId, 'QueueStatus', [])->catch(function (Throwable $throwable) use ($connection): void {
            $this->dropConnection($connection, 'QueueStatus failed: ' . $throwable->getMessage());
        });
    }

    private function handlePacket(AmiPacket $packet): void
    {
        if ($this->resync->acceptPacket($packet)) {
            return;
        }
        if ($packet->findField('Response') === null) {
            $this->eventHandler->handleEvent($packet);

            return;
        }
        $actionId = $packet->findActionId() ?? '';
        $pendingAction = $this->pendingActions[$actionId] ?? null;
        if ($pendingAction === null) {
            $this->logger->debug('Dropping an AMI reply for no pending action {actionId}', ['actionId' => $actionId]);

            return;
        }
        unset($this->pendingActions[$actionId]);
        $this->loop->cancelTimer($pendingAction['timer']);
        if ($packet->isSuccessResponse()) {
            $pendingAction['deferred']->resolve($packet);

            return;
        }
        $message = $packet->findField('Message') ?? '';
        $this->logger->warning('AMI action {action} {actionId} failed: {message}', [
            'action' => $pendingAction['action'],
            'actionId' => $actionId,
            'message' => $message,
        ]);
        $pendingAction['deferred']->reject(new AmiActionFailed($message));
    }

    /**
     * @param array<string, string> $fields
     * @return PromiseInterface<AmiPacket>
     */
    private function writeAction(ConnectionInterface $connection, string $actionId, string $action, array $fields): PromiseInterface
    {
        try {
            $wire = new AmiPacket(['Action' => $action, 'ActionID' => $actionId, ...$fields])->toWire();
        } catch (MalformedAmiPacket $exception) {
            return reject(new AmiActionFailed(sprintf('AMI action %s not sent: %s', $action, $exception->getMessage())));
        }
        /** @var Deferred<AmiPacket> $deferred */
        $deferred = new Deferred();
        $timer = $this->loop->addTimer($this->replyTimeoutSeconds, function () use ($actionId, $action, $deferred): void {
            unset($this->pendingActions[$actionId]);
            $this->logger->warning('AMI action {action} {actionId} timed out', ['action' => $action, 'actionId' => $actionId]);
            $deferred->reject(new AmiActionTimedOut(sprintf('AMI action %s got no reply within %s s.', $action, $this->replyTimeoutSeconds)));
        });
        $this->pendingActions[$actionId] = ['action' => $action, 'deferred' => $deferred, 'timer' => $timer];
        $connection->write($wire);

        return $deferred->promise();
    }

    private function dropConnection(ConnectionInterface $connection, string $reason): void
    {
        if ($connection !== $this->connection) {
            return;
        }
        $this->connection = null;
        $this->isLoggedIn = false;
        $this->logger->warning('AMI connection dropped: {reason}', ['reason' => $reason]);
        foreach ([$this->bannerTimer, $this->resyncTimer, $this->keepaliveTimer] as $connectionTimer) {
            if ($connectionTimer !== null) {
                $this->loop->cancelTimer($connectionTimer);
            }
        }
        $this->bannerTimer = null;
        $this->resyncTimer = null;
        $this->keepaliveTimer = null;
        $connection->close();
        $this->resync->abort();

        /*
         * Rejected, not re-sent after the reconnect as Python's panoramisk does: a late pause could undo the
         * agent's next click. The caller decides whether to retry.
         */
        $pendingActions = $this->pendingActions;
        $this->pendingActions = [];
        foreach ($pendingActions as $pendingAction) {
            $this->loop->cancelTimer($pendingAction['timer']);
            $pendingAction['deferred']->reject(new AmiConnectionLost(sprintf('The AMI connection dropped before %s got a reply.', $pendingAction['action'])));
        }

        $this->flushFloorChanges();
        $this->scheduleReconnect();
    }

    private function scheduleReconnect(): void
    {
        if ($this->isClosed) {
            return;
        }
        $delaySeconds = $this->reconnectDelaysSeconds[min($this->reconnectAttemptCount, count($this->reconnectDelaysSeconds) - 1)];
        ++$this->reconnectAttemptCount;
        $this->logger->info('Reconnecting to the exchange in {delaySeconds} s', ['delaySeconds' => $delaySeconds]);
        $this->reconnectTimer = $this->loop->addTimer($delaySeconds, $this->connect(...));
    }

    private function flushFloorChanges(): void
    {
        if ($this->flushChanges !== null) {
            ($this->flushChanges)();
        }
    }
}
