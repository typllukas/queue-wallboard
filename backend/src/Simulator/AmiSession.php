<?php

declare(strict_types=1);

namespace QueueWallboard\Simulator;

use Closure;
use QueueWallboard\Ami\AmiPacket;
use QueueWallboard\Ami\AmiPacketParser;
use QueueWallboard\Ami\MalformedAmiPacket;
use React\Socket\ConnectionInterface;

final class AmiSession
{
    /** the AMI version Asterisk 22 sends; the daemon checks only the prefix */
    private const string BANNER = "Asterisk Call Manager/11.0.0\r\n";
    /** the daemon sends only true and false */
    private const array TRUE_PAUSED_VALUES = ['true', 'yes', 'on', '1'];

    private readonly AmiPacketParser $parser;
    private readonly Closure $writeEvent;
    private bool $isLoggedIn = false;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly CallCentre $callCentre,
        private readonly string $username,
        private readonly string $secret,
    ) {
        $this->parser = new AmiPacketParser();
        $this->writeEvent = $this->write(...);
        $connection->write(self::BANNER);
        $connection->on('data', $this->readChunk(...));
        $connection->on('close', fn() => $callCentre->removeListener('event', $this->writeEvent));
    }

    public function close(): void
    {
        $this->connection->close();
    }

    private function readChunk(string $chunk): void
    {
        try {
            $actionPackets = $this->parser->pushChunk($chunk);
        } catch (MalformedAmiPacket) {
            $this->connection->end($this->createReply('Error', null, 'Malformed packet')->toWire());

            return;
        }
        foreach ($actionPackets as $actionPacket) {
            $this->answerAction($actionPacket);
        }
    }

    private function answerAction(AmiPacket $actionPacket): void
    {
        $actionId = $actionPacket->findActionId();
        $actionName = $actionPacket->findField('Action');
        if ($actionName === 'Login') {
            $this->login($actionPacket, $actionId);

            return;
        }
        if (!$this->isLoggedIn) {
            $this->write($this->createReply('Error', $actionId, 'Permission denied'));

            return;
        }
        match ($actionName) {
            'QueuePause' => $this->pauseMember($actionPacket, $actionId),
            'QueueStatus' => $this->describeQueueStatus($actionId),
            'Ping' => $this->write($this->createReply('Success', $actionId, null, ['Ping' => 'Pong', 'Timestamp' => sprintf('%.6f', microtime(true))])),
            default => $this->write($this->createReply('Error', $actionId, 'Invalid/unknown command')),
        };
    }

    private function login(AmiPacket $actionPacket, ?string $actionId): void
    {
        if ($actionPacket->findField('Username') !== $this->username || !hash_equals($this->secret, $actionPacket->findField('Secret') ?? '')) {
            $this->connection->end($this->createReply('Error', $actionId, 'Authentication failed')->toWire());

            return;
        }
        if (!$this->isLoggedIn) {
            $this->isLoggedIn = true;
            $this->callCentre->on('event', $this->writeEvent);
        }
        $this->write($this->createReply('Success', $actionId, 'Authentication accepted'));
    }

    private function pauseMember(AmiPacket $actionPacket, ?string $actionId): void
    {
        $isPaused = in_array(strtolower($actionPacket->findField('Paused') ?? ''), self::TRUE_PAUSED_VALUES, true);
        if (!$this->callCentre->pauseMember($actionPacket->findField('Interface') ?? '', $isPaused, $actionPacket->findField('Reason'))) {
            $this->write($this->createReply('Error', $actionId, 'Interface not found'));

            return;
        }
        $this->write($this->createReply('Success', $actionId, $isPaused ? 'Interface paused successfully' : 'Interface unpaused successfully'));
    }

    private function describeQueueStatus(?string $actionId): void
    {
        $this->write($this->createReply('Success', $actionId, 'Queue status will follow', ['EventList' => 'start']));
        foreach ($this->callCentre->describeQueueStatus($actionId ?? '') as $statusPacket) {
            $this->write($statusPacket);
        }
    }

    /**
     * @param array<string, string> $extraFields
     */
    private function createReply(string $response, ?string $actionId, ?string $message, array $extraFields = []): AmiPacket
    {
        $fields = ['Response' => $response];
        if ($actionId !== null) {
            $fields['ActionID'] = $actionId;
        }
        $fields = [...$fields, ...$extraFields];
        if ($message !== null) {
            $fields['Message'] = $message;
        }

        return new AmiPacket($fields);
    }

    private function write(AmiPacket $packet): void
    {
        $this->connection->write($packet->toWire());
    }
}
