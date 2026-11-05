<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Simulator;

use PHPUnit\Framework\Assert;
use QueueWallboard\Ami\AmiPacket;
use QueueWallboard\Ami\AmiPacketParser;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;

use function React\Async\await;
use function React\Async\delay;

final class AmiTestClient
{
    private const float WAIT_STEP_SECONDS = 0.01;
    private const float WAIT_LIMIT_SECONDS = 2.0;

    private string $unreadBanner = '';
    private ?string $banner = null;
    private int $readPacketCount = 0;
    private readonly AmiPacketParser $parser;

    /** @var list<AmiPacket> */
    private array $packets = [];

    private function __construct(private readonly ConnectionInterface $connection)
    {
        $this->parser = new AmiPacketParser();
        $connection->on('data', function (string $chunk): void {
            if ($this->banner === null) {
                $this->unreadBanner .= $chunk;
                $bannerEnd = strpos($this->unreadBanner, "\r\n");
                if ($bannerEnd === false) {
                    return;
                }
                $this->banner = substr($this->unreadBanner, 0, $bannerEnd + 2);
                $chunk = substr($this->unreadBanner, $bannerEnd + 2);
            }
            array_push($this->packets, ...$this->parser->pushChunk($chunk));
        });
    }

    public static function connect(string $address): self
    {
        return new self(await(new Connector()->connect($address)));
    }

    public function awaitBanner(): string
    {
        $this->awaitCondition(fn(): bool => $this->banner !== null);

        return $this->banner ?? '';
    }

    public function sendRaw(string $wire): void
    {
        $this->connection->write($wire);
    }

    /**
     * @param array<string, string> $fields
     */
    public function send(array $fields): void
    {
        $this->connection->write(new AmiPacket($fields)->toWire());
    }

    /**
     * @return list<AmiPacket>
     */
    public function awaitPackets(int $packetCount): array
    {
        $this->awaitCondition(fn(): bool => count($this->packets) - $this->readPacketCount >= $packetCount);
        $unreadPackets = array_slice($this->packets, $this->readPacketCount, $packetCount);
        $this->readPacketCount += count($unreadPackets);

        return $unreadPackets;
    }

    /**
     * @return list<AmiPacket>
     */
    public function listReceivedPackets(): array
    {
        return $this->packets;
    }

    public function awaitClose(): void
    {
        $isClosed = false;
        $this->connection->on('close', function () use (&$isClosed): void {
            $isClosed = true;
        });
        $this->awaitCondition(fn(): bool => $isClosed || !$this->connection->isWritable());
    }

    public function isOpen(): bool
    {
        return $this->connection->isWritable();
    }

    public function close(): void
    {
        $this->connection->close();
    }

    /**
     * @param callable(): bool $isMet
     */
    private function awaitCondition(callable $isMet): void
    {
        for ($waitedSeconds = 0.0; $waitedSeconds < self::WAIT_LIMIT_SECONDS && !$isMet(); $waitedSeconds += self::WAIT_STEP_SECONDS) {
            delay(self::WAIT_STEP_SECONDS);
        }

        Assert::assertTrue($isMet(), sprintf('The awaited condition did not hold within %.1f s.', self::WAIT_LIMIT_SECONDS));
    }
}
