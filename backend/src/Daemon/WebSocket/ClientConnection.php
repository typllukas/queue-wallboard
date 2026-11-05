<?php

declare(strict_types=1);

namespace QueueWallboard\Daemon\WebSocket;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use QueueWallboard\Daemon\Wire\WireMapper;
use Ratchet\RFC6455\Messaging\Frame;
use React\Stream\WritableStreamInterface;

final class ClientConnection
{
    /** far above one snapshot; changes wait for a slow client, every other message still queues in memory */
    private const int MAX_BYTES_WRITTEN_WHILE_SLOW = 1_048_576;

    private bool $isSlow = false;
    private int $bytesWrittenWhileSlow = 0;
    private DateTimeImmutable $lastPongAt;

    /** @var array<int|string, array{id_agent: string, ...<string, mixed>}> */
    private array $heldAgents = [];

    /** @var array<int|string, array{name: string, ...<string, mixed>}> */
    private array $heldQueues = [];

    public function __construct(
        private readonly WritableStreamInterface $stream,
        private readonly ClockInterface $clock,
        public readonly ?string $agentExtension,
    ) {
        $this->lastPongAt = $clock->now();
        $stream->on('drain', $this->sendHeldChanges(...));
    }

    /**
     * @param array<string, mixed> $message
     */
    public function sendMessage(array $message): void
    {
        $this->writeFrame(new Frame(json_encode($message, WireMapper::JSON_ENCODE_FLAGS), true, Frame::OP_TEXT));
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function sendSnapshot(array $snapshot): void
    {
        $this->heldAgents = [];
        $this->heldQueues = [];
        $this->sendMessage($snapshot);
    }

    /**
     * @param list<array{id_agent: string, ...<string, mixed>}> $agents
     * @param list<array{name: string, ...<string, mixed>}> $queues
     */
    public function sendChanges(array $agents, array $queues): void
    {
        if ($this->isSlow) {
            foreach ($agents as $agent) {
                $this->heldAgents[$agent['id_agent']] = $agent;
            }
            foreach ($queues as $queue) {
                $this->heldQueues[$queue['name']] = $queue;
            }

            return;
        }
        if ($agents !== []) {
            $this->sendMessage(['action' => 'agentsChanged', 'agents' => $agents]);
        }
        if ($queues !== []) {
            $this->sendMessage(['action' => 'queuesChanged', 'queues' => $queues]);
        }
    }

    public function answerPing(string $applicationData): void
    {
        $this->writeFrame(new Frame($applicationData, true, Frame::OP_PONG));
    }

    public function recordPong(): void
    {
        $this->lastPongAt = $this->clock->now();
    }

    public function isSilentSince(DateTimeImmutable $limit): bool
    {
        return $this->lastPongAt < $limit;
    }

    public function close(int $statusCode = Frame::CLOSE_NORMAL): void
    {
        $this->writeFrame(new Frame(pack('n', $statusCode), true, Frame::OP_CLOSE));
        $this->stream->end();
    }

    private function sendHeldChanges(): void
    {
        $agents = array_values($this->heldAgents);
        $queues = array_values($this->heldQueues);
        $this->heldAgents = [];
        $this->heldQueues = [];
        $this->isSlow = false;
        $this->bytesWrittenWhileSlow = 0;
        $this->sendChanges($agents, $queues);
    }

    private function writeFrame(Frame $frame): void
    {
        if (!$this->stream->isWritable()) {
            return;
        }
        $frameContents = $frame->getContents();
        if ($this->isSlow) {
            $this->bytesWrittenWhileSlow += strlen($frameContents);
            if ($this->bytesWrittenWhileSlow > self::MAX_BYTES_WRITTEN_WHILE_SLOW) {
                $this->stream->end();

                return;
            }
        }
        if (!$this->stream->write($frameContents)) {
            $this->isSlow = true;
        }
    }
}
