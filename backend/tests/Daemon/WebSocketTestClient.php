<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Daemon;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\ResponseInterface;
use Ratchet\RFC6455\Handshake\ClientNegotiator;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\FrameInterface;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use RuntimeException;
use UnexpectedValueException;

use function React\Async\await;
use function React\Async\delay;

final class WebSocketTestClient
{
    private const float WAIT_STEP_SECONDS = 0.01;
    private const float WAIT_LIMIT_SECONDS = 3.0;

    /** @var list<array<mixed>> */
    public private(set) array $receivedMessages = [];

    /** @var list<string> */
    public private(set) array $receivedPongPayloads = [];

    public private(set) int $receivedPingCount = 0;
    public private(set) ?int $receivedCloseCode = null;
    public private(set) bool $isClosed = false;
    private bool $isAnsweringPings = true;
    private ?string $unreadHandshakeResponse = '';
    private ?ResponseInterface $handshakeResponse = null;
    private readonly MessageBuffer $messageBuffer;

    private function __construct(private readonly ConnectionInterface $connection)
    {
        $this->messageBuffer = new MessageBuffer(
            new CloseFrameChecker(),
            $this->receiveMessage(...),
            $this->receiveControlFrame(...),
            false,
        );
        $connection->on('data', $this->readChunk(...));
        $connection->on('close', function (): void {
            $this->isClosed = true;
        });
    }

    /**
     * @param array<string, string> $headers
     */
    public static function open(string $serverAddress, string $pathAndQuery, array $headers = []): self
    {
        $negotiator = new ClientNegotiator(new HttpFactory());
        $handshakeRequest = $negotiator->generateRequest(new Uri(str_replace('tcp://', 'ws://', $serverAddress) . $pathAndQuery));
        foreach ($headers as $name => $value) {
            $handshakeRequest = $handshakeRequest->withHeader($name, $value);
        }
        $client = new self(await(new Connector()->connect($serverAddress)));
        $client->connection->write(Message::toString($handshakeRequest));
        self::awaitCondition(fn(): bool => $client->handshakeResponse !== null || $client->isClosed);
        if ($client->handshakeResponse === null || !$negotiator->validateResponse($handshakeRequest, $client->handshakeResponse)) {
            throw new RuntimeException('The WebSocket handshake failed.');
        }

        return $client;
    }

    public function sendFrame(int $opcode, string $payload): void
    {
        $this->sendFramesInOneChunk(new Frame($payload, true, $opcode));
    }

    public function sendFramesInOneChunk(Frame ...$frames): void
    {
        $chunk = '';
        foreach ($frames as $frame) {
            $frame->maskPayload();
            $chunk .= $frame->getContents();
        }
        $this->connection->write($chunk);
    }

    /**
     * @param array<string, mixed> $clientMessage
     */
    public function sendClientMessage(array $clientMessage): void
    {
        $this->sendFrame(Frame::OP_TEXT, json_encode($clientMessage, JSON_THROW_ON_ERROR));
    }

    public function stopAnsweringPings(): void
    {
        $this->isAnsweringPings = false;
    }

    public function close(): void
    {
        $this->connection->close();
    }

    /**
     * @return list<array<mixed>>
     */
    public function awaitMessages(int $messageCount): array
    {
        self::awaitCondition(fn(): bool => count($this->receivedMessages) >= $messageCount);

        return $this->receivedMessages;
    }

    public function findNewestPauseOfAgent(string $extension): mixed
    {
        $newestPause = null;
        foreach ($this->receivedMessages as $message) {
            if (($message['action'] ?? null) !== 'agentsChanged' || !is_array($message['agents'] ?? null)) {
                continue;
            }
            foreach ($message['agents'] as $agent) {
                if (is_array($agent) && ($agent['id_agent'] ?? null) === $extension) {
                    $newestPause = $agent['id_pause'] ?? null;
                }
            }
        }

        return $newestPause;
    }

    /**
     * @param callable(): bool $isMet
     */
    public static function awaitCondition(callable $isMet): void
    {
        for ($waitedSeconds = 0.0; $waitedSeconds < self::WAIT_LIMIT_SECONDS && !$isMet(); $waitedSeconds += self::WAIT_STEP_SECONDS) {
            delay(self::WAIT_STEP_SECONDS);
        }
    }

    private function readChunk(string $chunk): void
    {
        if ($this->unreadHandshakeResponse !== null) {
            $this->unreadHandshakeResponse .= $chunk;
            $headerEnd = strpos($this->unreadHandshakeResponse, "\r\n\r\n");
            if ($headerEnd === false) {
                return;
            }
            $this->handshakeResponse = Message::parseResponse(substr($this->unreadHandshakeResponse, 0, $headerEnd + 4));
            $chunk = substr($this->unreadHandshakeResponse, $headerEnd + 4);
            $this->unreadHandshakeResponse = null;
        }
        $this->messageBuffer->onData($chunk);
    }

    /**
     * @param MessageInterface<FrameInterface> $message
     */
    private function receiveMessage(MessageInterface $message): void
    {
        $decodedMessage = json_decode($message->getPayload(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decodedMessage)) {
            throw new UnexpectedValueException('The daemon sent a message that is not a JSON object.');
        }
        if (($decodedMessage['action'] ?? null) === 'ping') {
            ++$this->receivedPingCount;
            if ($this->isAnsweringPings) {
                $this->sendClientMessage(['action' => 'pong']);
            }

            return;
        }
        $this->receivedMessages[] = $decodedMessage;
    }

    private function receiveControlFrame(FrameInterface $frame): void
    {
        if ($frame->getOpcode() === Frame::OP_PONG) {
            $this->receivedPongPayloads[] = $frame->getPayload();
        }
        if ($frame->getOpcode() === Frame::OP_CLOSE) {
            $closePayloadFields = unpack('nstatusCode', $frame->getPayload());
            $this->receivedCloseCode = is_array($closePayloadFields) && is_int($closePayloadFields['statusCode']) ? $closePayloadFields['statusCode'] : null;
        }
    }
}
