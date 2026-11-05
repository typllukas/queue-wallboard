<?php

declare(strict_types=1);

namespace QueueWallboard\Daemon;

use InvalidArgumentException;
use Override;
use Psr\Log\LogLevel;
use QueueWallboard\Daemon\Ami\AmiClient;
use QueueWallboard\Daemon\Ami\AmiEventHandler;
use QueueWallboard\Daemon\Ami\QueueStatusResync;
use QueueWallboard\Daemon\Floor\FloorState;
use QueueWallboard\Daemon\Floor\QueueSettings;
use QueueWallboard\Daemon\LongPoll\ChangeHistory;
use QueueWallboard\Daemon\LongPoll\LongPollEndpoint;
use QueueWallboard\Daemon\WebSocket\WebSocketServer;
use QueueWallboard\Daemon\Wire\WireMapper;
use Ratchet\RFC6455\Messaging\Frame;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Socket\LimitingServer;
use React\Socket\SocketServer;
use RuntimeException;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'daemon:run', description: 'Run the daemon: AMI client, WebSocket server and broadcast')]
final class DaemonCommand extends Command
{
    /** all interfaces, so the other containers reach it; the port is arbitrary */
    private const string LISTEN_ADDRESS = '0.0.0.0:8090';
    /** below the container's limit of 1024 open files, so the AMI connection and the log still get one */
    private const int MAX_CONNECTIONS = 900;
    /** 5038 is the default AMI port of Asterisk */
    private const string DEFAULT_EXCHANGE_ADDRESS = 'tcp://simulator:5038';
    /** only the shape; an exchange that does not resolve yet is retried, not refused */
    private const string EXCHANGE_ADDRESS_PATTERN = '#^tcp://[^/?\#]+:\d+$#D';
    /** also how late after midnight the daily counters can reset */
    private const float HOUSEKEEPING_INTERVAL_SECONDS = 60.0;
    /** only has to differ from the previous run, 4 bytes is plenty */
    private const int BOOT_ID_BYTES = 4;
    /** time for the close frames and 503 answers to go out, well inside the 10 s Docker waits */
    private const float SHUTDOWN_FLUSH_SECONDS = 0.5;

    #[Override]
    protected function configure(): void
    {
        $this->addOption('exchange', null, InputOption::VALUE_REQUIRED, 'Address of the AMI server, tcp://host:port', self::DEFAULT_EXCHANGE_ADDRESS);
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $exchangeAddress = $input->getOption('exchange');
        if (!is_string($exchangeAddress) || preg_match(self::EXCHANGE_ADDRESS_PATTERN, $exchangeAddress) !== 1) {
            throw new InvalidArgumentException(sprintf('The exchange address must be tcp://host:port, for example %s.', self::DEFAULT_EXCHANGE_ADDRESS));
        }
        $logger = new ConsoleEscapingLogger(new ConsoleLogger(
            $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output,
            [LogLevel::NOTICE => OutputInterface::VERBOSITY_NORMAL, LogLevel::INFO => OutputInterface::VERBOSITY_NORMAL],
        ));
        $settings = DaemonSettings::createDefaults();
        $loop = Loop::get();
        $clock = new NativeClock($this->readEnvironment('FLOOR_TIMEZONE'));

        $floor = new FloorState(QueueSettings::createDemoSettings(), $clock);
        $eventHandler = new AmiEventHandler($floor, $clock);
        $amiClient = new AmiClient(
            $exchangeAddress,
            $this->readEnvironment('AMI_USERNAME'),
            $this->readEnvironment('AMI_SECRET'),
            $loop,
            new QueueStatusResync($floor, $eventHandler, $clock, $logger),
            $eventHandler,
            $logger,
            $settings->amiReplyTimeoutSeconds,
            $settings->resyncTimeoutSeconds,
            $settings->amiKeepaliveIntervalSeconds,
        );
        $wireMapper = new WireMapper($floor);
        $agentCommands = new AgentCommands($amiClient);
        $heartbeat = new Heartbeat($loop, $settings, $clock, $floor, $agentCommands, $logger);
        $webSocketServer = new WebSocketServer($loop, $floor, $wireMapper, $heartbeat, $agentCommands, $clock, $logger, explode(',', $this->readEnvironment('WEB_ORIGINS')));
        $longPollEndpoint = new LongPollEndpoint(
            $loop,
            new ChangeHistory(bin2hex(random_bytes(self::BOOT_ID_BYTES)), $settings->longPollHistoryLength),
            $wireMapper,
            $settings->longPollSeconds,
        );
        $broadcaster = new Broadcaster($floor, $wireMapper, $webSocketServer, $longPollEndpoint);
        $amiClient->registerFloorChangeListener($broadcaster->flushChanges(...));

        $httpServer = new HttpServer(new HttpRouter($webSocketServer, $longPollEndpoint)->handleRequest(...));
        $httpServer->on('error', static fn(Throwable $throwable) => $logger->error('HTTP request failed: {error}', ['error' => $throwable->getMessage()]));
        $listeningSocket = new LimitingServer(new SocketServer(self::LISTEN_ADDRESS), self::MAX_CONNECTIONS);
        $httpServer->listen($listeningSocket);

        $loop->addPeriodicTimer(self::HOUSEKEEPING_INTERVAL_SECONDS, static function () use ($floor, $broadcaster, $logger): void {
            $floor->resetCountersIfNewDay();
            $broadcaster->flushChanges();
            $logger->info('Memory in use: {usedMemoryBytes} bytes allocated, {reservedMemoryBytes} bytes reserved from the system', [
                'usedMemoryBytes' => memory_get_usage(),
                'reservedMemoryBytes' => memory_get_usage(true),
            ]);
        });

        $shutDown = static function (int $signal) use ($loop, $heartbeat, $webSocketServer, $longPollEndpoint, $amiClient, $listeningSocket, $logger): void {
            $logger->notice('Shutting down on signal {signal}', ['signal' => $signal]);
            $heartbeat->stop();
            $webSocketServer->closeAllConnections(Frame::CLOSE_GOING_AWAY);
            $listeningSocket->close();
            $longPollEndpoint->refuseHeldRequests();
            $amiClient->close();
            $loop->addTimer(self::SHUTDOWN_FLUSH_SECONDS, $loop->stop(...));
        };
        $loop->addSignal(SIGTERM, $shutDown);
        $loop->addSignal(SIGINT, $shutDown);

        $amiClient->connect();
        $logger->info('Daemon listening on {listenAddress}, exchange at {exchangeAddress}', [
            'listenAddress' => self::LISTEN_ADDRESS,
            'exchangeAddress' => $exchangeAddress,
        ]);
        $loop->run();

        return Command::SUCCESS;
    }

    private function readEnvironment(string $name): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            throw new RuntimeException(sprintf('Environment variable %s is not set.', $name));
        }

        return $value;
    }
}
