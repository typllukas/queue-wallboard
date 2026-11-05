<?php

declare(strict_types=1);

namespace QueueWallboard\Daemon\WebSocket;

use GuzzleHttp\Psr7\HttpFactory;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use QueueWallboard\Ami\AmiPacket;
use QueueWallboard\Daemon\AgentCommands;
use QueueWallboard\Daemon\Ami\AmiActionFailed;
use QueueWallboard\Daemon\Ami\AmiActionTimedOut;
use QueueWallboard\Daemon\Ami\AmiConnectionLost;
use QueueWallboard\Daemon\Floor\FloorState;
use QueueWallboard\Daemon\Heartbeat;
use QueueWallboard\Daemon\Wire\WireMapper;
use Ratchet\RFC6455\Handshake\RequestVerifier;
use Ratchet\RFC6455\Handshake\ServerNegotiator;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\FrameInterface;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\LoopInterface;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use React\Stream\CompositeStream;
use React\Stream\ThroughStream;

final class WebSocketServer
{
    /** commands are short, and one client must not fill the memory with a single message */
    public const int MAX_MESSAGE_BYTES = 4096;
    /** extensions are short numbers, 10 digits is a generous limit */
    private const string EXTENSION_PATTERN = '/^\d{1,10}$/D';

    private readonly ServerNegotiator $negotiator;

    /** @var array<int, ClientConnection> */
    private array $connections = [];

    /**
     * @param list<string> $allowedOrigins
     */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly FloorState $floor,
        private readonly WireMapper $wireMapper,
        private readonly Heartbeat $heartbeat,
        private readonly AgentCommands $agentCommands,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly array $allowedOrigins,
    ) {
        $this->negotiator = new ServerNegotiator(new RequestVerifier(), new HttpFactory());
    }

    public function handleUpgrade(ServerRequestInterface $request): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        if ($origin !== '' && !in_array($origin, $this->allowedOrigins, true)) {
            $this->logger->warning('Refused a WebSocket upgrade from origin {origin} ({remoteAddress})', [
                'origin' => $origin,
                'remoteAddress' => $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown',
            ]);

            return new Response(Response::STATUS_FORBIDDEN);
        }
        $requestedAgentExtension = $request->getQueryParams()['agent'] ?? null;
        if ($requestedAgentExtension !== null && (!is_string($requestedAgentExtension) || preg_match(self::EXTENSION_PATTERN, $requestedAgentExtension) !== 1)) {
            return new Response(Response::STATUS_BAD_REQUEST);
        }
        $handshakeResponse = $this->negotiator->handshake($request);
        if ($handshakeResponse->getStatusCode() !== Response::STATUS_SWITCHING_PROTOCOLS) {
            return $handshakeResponse;
        }

        $toClient = new ThroughStream();
        $fromClient = new ThroughStream();
        $connection = new ClientConnection($toClient, $this->clock, $requestedAgentExtension);
        $messageBuffer = new MessageBuffer(
            new CloseFrameChecker(),
            fn(MessageInterface $message) => $this->routeMessage($connection, $message->getPayload()),
            fn(FrameInterface $frame) => $this->answerControlFrame($connection, $frame),
            true,
            null,
            self::MAX_MESSAGE_BYTES,
            self::MAX_MESSAGE_BYTES,
        );
        $fromClient->on('data', $messageBuffer->onData(...));
        // wait until react/http has sent the 101 and connected the stream to the socket, or the snapshot is lost
        $this->loop->futureTick(fn() => $this->openConnection($connection, $toClient));

        /** @var array<string, list<string>> $handshakeHeaders */
        $handshakeHeaders = $handshakeResponse->getHeaders();

        return new Response(Response::STATUS_SWITCHING_PROTOCOLS, $handshakeHeaders, new CompositeStream($toClient, $fromClient));
    }

    /**
     * @return list<ClientConnection>
     */
    public function listConnections(): array
    {
        return array_values($this->connections);
    }

    public function closeAllConnections(int $statusCode): void
    {
        foreach ($this->connections as $connection) {
            $connection->close($statusCode);
        }
    }

    private function openConnection(ClientConnection $connection, ThroughStream $toClient): void
    {
        if (!$toClient->isWritable()) {
            return;
        }
        $agentExtension = $connection->agentExtension;
        // before the first resync the floor is empty and every agent would look unknown
        if ($agentExtension !== null && $this->floor->listAgents() !== [] && $this->floor->findAgent($agentExtension) === null) {
            $this->logger->notice('Closing a WebSocket connection for unknown agent {extension}', ['extension' => $agentExtension]);
            $connection->sendMessage(['action' => 'error', 'message' => sprintf('Unknown agent %s', $agentExtension)]);
            $connection->close(Frame::CLOSE_POLICY);

            return;
        }

        $connection->sendMessage($this->wireMapper->mapSnapshot());
        $connectionKey = spl_object_id($connection);
        $this->connections[$connectionKey] = $connection;
        $this->heartbeat->watchConnection($connection);
        $toClient->on('close', function () use ($connectionKey, $connection): void {
            unset($this->connections[$connectionKey]);
            $this->heartbeat->forgetConnection($connection);
            $this->logger->info('WebSocket connection closed, {connectionCount} open', ['connectionCount' => count($this->connections)]);
        });
        $this->logger->info('WebSocket connection opened, {connectionCount} open', ['connectionCount' => count($this->connections)]);
    }

    private function routeMessage(ClientConnection $connection, string $payload): void
    {
        if (!isset($this->connections[spl_object_id($connection)])) {
            return;
        }
        try {
            $clientMessage = ClientMessage::fromJson($payload);
        } catch (InvalidClientMessage $exception) {
            $this->logger->notice('Rejected an invalid WebSocket message (agent {extension}): {error}', [
                'extension' => $connection->agentExtension ?? 'none',
                'error' => $exception->getMessage(),
            ]);
            $connection->sendMessage(['action' => 'error', 'message' => 'Invalid message']);

            return;
        }
        if ($clientMessage->isPong()) {
            $connection->recordPong();

            return;
        }
        $extension = $connection->agentExtension;
        if ($extension === null) {
            $this->logger->notice('Rejected a pause from a connection with no agent');
            $connection->sendMessage(['action' => 'error', 'message' => 'This connection has no agent']);

            return;
        }
        if ($clientMessage->isUnpause()) {
            $this->reportUnconfirmedPause($connection, $extension, $this->agentCommands->unpauseAgent($extension), 'The PBX did not confirm the end of the pause. Try again.');

            return;
        }
        $pauseReason = $clientMessage->findPauseReason();
        if ($clientMessage->isPause() && $pauseReason !== null) {
            $this->reportUnconfirmedPause($connection, $extension, $this->agentCommands->pauseAgent($extension, $pauseReason), 'The PBX did not confirm the pause. Try again.');
        }
    }

    /**
     * @param PromiseInterface<AmiPacket> $queuePause
     */
    private function reportUnconfirmedPause(ClientConnection $connection, string $extension, PromiseInterface $queuePause, string $notConfirmedError): void
    {
        $queuePause->catch(function (AmiActionFailed|AmiActionTimedOut|AmiConnectionLost $exception) use ($connection, $extension, $notConfirmedError): void {
            $this->logger->warning('The exchange did not confirm QueuePause for agent {extension}: {error}', [
                'extension' => $extension,
                'error' => $exception->getMessage(),
            ]);
            $connection->sendMessage(['action' => 'error', 'message' => $notConfirmedError]);
        });
    }

    private function answerControlFrame(ClientConnection $connection, FrameInterface $frame): void
    {
        if ($frame->getOpcode() === Frame::OP_PING) {
            $connection->answerPing($frame->getPayload());

            return;
        }
        if ($frame->getOpcode() === Frame::OP_CLOSE) {
            $closePayloadFields = unpack('nstatusCode', $frame->getPayload());
            $connection->close(is_array($closePayloadFields) && is_int($closePayloadFields['statusCode']) ? $closePayloadFields['statusCode'] : Frame::CLOSE_PROTOCOL);
        }
    }
}
