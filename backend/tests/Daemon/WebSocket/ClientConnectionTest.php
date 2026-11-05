<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Daemon\WebSocket;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueueWallboard\Daemon\WebSocket\ClientConnection;
use Ratchet\RFC6455\Messaging\Frame;
use React\Stream\ThroughStream;
use Symfony\Component\Clock\MockClock;

#[CoversClass(ClientConnection::class)]
final class ClientConnectionTest extends TestCase
{
    private MockClock $clock;
    private ThroughStream $stream;

    /** @var list<Frame> */
    private array $writtenFrames = [];

    #[Override]
    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-03 10:00:00 Europe/Prague');
        $this->stream = new ThroughStream();
        $this->stream->on('data', function (string $bytes): void {
            $frame = new Frame();
            $frame->addBuffer($bytes);
            $this->writtenFrames[] = $frame;
        });
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->stream->close();
    }

    public function testSendMessageWritesATextFrameWithTheJson(): void
    {
        $connection = $this->createConnection(null);

        $connection->sendMessage(['action' => 'ping']);

        self::assertCount(1, $this->writtenFrames);
        self::assertSame(Frame::OP_TEXT, $this->writtenFrames[0]->getOpcode());
        self::assertSame('{"action":"ping"}', $this->writtenFrames[0]->getPayload());
    }

    public function testAnInvalidUtf8ByteInAnAgentTitleIsReplacedInsteadOfThrowing(): void
    {
        $connection = $this->createConnection(null);

        $connection->sendChanges([['id_agent' => '203', 'title' => "Lucie Dvo\xF8\xE1kov\xE1"]], []);

        self::assertSame(
            [['action' => 'agentsChanged', 'agents' => [['id_agent' => '203', 'title' => "Lucie Dvo\u{FFFD}\u{FFFD}kov\u{FFFD}"]]]],
            $this->decodeWrittenMessages(),
        );
    }

    public function testSendChangesWritesOneMessagePerKindWhileTheClientKeepsUp(): void
    {
        $connection = $this->createConnection(null);

        $connection->sendChanges([['id_agent' => '203']], [['name' => 'support']]);

        self::assertSame([
            ['action' => 'agentsChanged', 'agents' => [['id_agent' => '203']]],
            ['action' => 'queuesChanged', 'queues' => [['name' => 'support']]],
        ], $this->decodeWrittenMessages());
    }

    public function testSendChangesSkipsAKindWithoutChanges(): void
    {
        $connection = $this->createConnection(null);

        $connection->sendChanges([], [['name' => 'support']]);

        self::assertSame([['action' => 'queuesChanged', 'queues' => [['name' => 'support']]]], $this->decodeWrittenMessages());
    }

    public function testASlowClientGetsOnlyTheNewestStateAfterDrain(): void
    {
        $connection = $this->createConnection(null);
        $this->stream->pause();
        $connection->sendMessage(['action' => 'snapshot']);
        $this->writtenFrames = [];

        $connection->sendChanges([['id_agent' => '203', 'id_pause' => 'lunch']], [['name' => 'support', 'total' => 1]]);
        $connection->sendChanges([['id_agent' => '203', 'id_pause' => 'training']], []);
        $connection->sendChanges([['id_agent' => '203', 'id_pause' => 'lunch']], [['name' => 'support', 'total' => 2]]);

        self::assertSame([], $this->writtenFrames);

        $this->stream->resume();

        self::assertSame([
            ['action' => 'agentsChanged', 'agents' => [['id_agent' => '203', 'id_pause' => 'lunch']]],
            ['action' => 'queuesChanged', 'queues' => [['name' => 'support', 'total' => 2]]],
        ], $this->decodeWrittenMessages());
    }

    public function testChangesAreWrittenDirectlyAgainAfterDrain(): void
    {
        $connection = $this->createConnection(null);
        $this->stream->pause();
        $connection->sendMessage(['action' => 'snapshot']);
        $connection->sendChanges([['id_agent' => '203']], []);
        $this->stream->resume();
        $this->writtenFrames = [];

        $connection->sendChanges([['id_agent' => '201']], []);

        self::assertSame([['action' => 'agentsChanged', 'agents' => [['id_agent' => '201']]]], $this->decodeWrittenMessages());
    }

    public function testASnapshotDropsTheChangesHeldForASlowClient(): void
    {
        $connection = $this->createConnection(null);
        $this->stream->pause();
        $connection->sendMessage(['action' => 'snapshot']);
        $connection->sendChanges([['id_agent' => '203', 'id_pause' => 'lunch']], []);
        $connection->sendSnapshot(['action' => 'snapshot', 'agents' => []]);
        $this->writtenFrames = [];

        $this->stream->resume();

        self::assertSame([], $this->writtenFrames);
    }

    public function testPingsAreStillWrittenToASlowClient(): void
    {
        $connection = $this->createConnection(null);
        $this->stream->pause();
        $connection->sendMessage(['action' => 'snapshot']);
        $this->writtenFrames = [];

        $connection->sendMessage(['action' => 'ping']);

        self::assertSame([['action' => 'ping']], $this->decodeWrittenMessages());
    }

    public function testAClientThatStopsReadingIsDisconnectedInsteadOfFillingTheMemory(): void
    {
        $connection = $this->createConnection(null);
        $this->stream->pause();
        $connection->sendMessage(['action' => 'snapshot']);

        foreach (range(1, 10_000) as $pingNumber) {
            $connection->answerPing(str_repeat('p', 125) . $pingNumber);
        }

        self::assertFalse($this->stream->isWritable());
    }

    public function testDrainingResetsWhatASlowClientMayStillReceive(): void
    {
        $connection = $this->createConnection(null);
        for ($slowPeriodIndex = 0; $slowPeriodIndex < 20; ++$slowPeriodIndex) {
            $this->stream->pause();
            $connection->sendMessage(['action' => 'snapshot', 'padding' => str_repeat('x', 100_000)]);
            $connection->sendMessage(['action' => 'snapshot', 'padding' => str_repeat('x', 100_000)]);
            $this->stream->resume();
        }

        self::assertTrue($this->stream->isWritable());
    }

    public function testAConnectionIsSilentWhenNoPongArrivedSinceTheLimit(): void
    {
        $connection = $this->createConnection(null);
        $this->clock->sleep(8);
        self::assertFalse($connection->isSilentSince($this->clock->now()->modify('-10 seconds')));

        $this->clock->sleep(5);
        self::assertTrue($connection->isSilentSince($this->clock->now()->modify('-10 seconds')));

        $connection->recordPong();
        self::assertFalse($connection->isSilentSince($this->clock->now()->modify('-10 seconds')));
    }

    public function testCloseWritesACloseFrameWithTheCodeAndEndsTheStream(): void
    {
        $connection = $this->createConnection(null);

        $connection->close(Frame::CLOSE_POLICY);

        self::assertCount(1, $this->writtenFrames);
        self::assertSame(Frame::OP_CLOSE, $this->writtenFrames[0]->getOpcode());
        self::assertSame(pack('n', Frame::CLOSE_POLICY), $this->writtenFrames[0]->getPayload());
        self::assertFalse($this->stream->isWritable());
    }

    public function testNothingIsWrittenAfterClose(): void
    {
        $connection = $this->createConnection(null);
        $connection->close();
        $this->writtenFrames = [];

        $connection->sendMessage(['action' => 'ping']);
        $connection->sendChanges([['id_agent' => '203']], []);

        self::assertSame([], $this->writtenFrames);
    }

    private function createConnection(?string $agentExtension): ClientConnection
    {
        return new ClientConnection($this->stream, $this->clock, $agentExtension);
    }

    /**
     * @return list<mixed>
     */
    private function decodeWrittenMessages(): array
    {
        return array_map(
            static fn(Frame $frame): mixed => json_decode($frame->getPayload(), true, 512, JSON_THROW_ON_ERROR),
            $this->writtenFrames,
        );
    }
}
