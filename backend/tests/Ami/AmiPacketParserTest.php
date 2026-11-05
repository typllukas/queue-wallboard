<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Ami;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueueWallboard\Ami\AmiPacket;
use QueueWallboard\Ami\AmiPacketParser;
use QueueWallboard\Ami\MalformedAmiPacket;

#[CoversClass(AmiPacketParser::class)]
final class AmiPacketParserTest extends TestCase
{
    public function testParsesOnePacket(): void
    {
        $packets = new AmiPacketParser()->pushChunk("Event: QueueCallerJoin\r\nQueue: sales\r\nCount: 1\r\n\r\n");

        self::assertCount(1, $packets);
        self::assertSame('sales', $packets[0]->getField('Queue'));
        self::assertSame('QueueCallerJoin', $packets[0]->findEventName());
    }

    public function testParsesTwoPacketsInOneChunk(): void
    {
        $packets = new AmiPacketParser()->pushChunk(
            "Event: QueueCallerJoin\r\nQueue: sales\r\n\r\nEvent: QueueCallerLeave\r\nQueue: support\r\n\r\n",
        );

        self::assertCount(2, $packets);
        self::assertSame('sales', $packets[0]->getField('Queue'));
        self::assertSame('support', $packets[1]->getField('Queue'));
    }

    public function testKeepsAPacketSplitMidLineUntilItCompletes(): void
    {
        $parser = new AmiPacketParser();

        self::assertSame([], $parser->pushChunk("Event: QueueCallerJoin\r\nQue"));

        $packets = $parser->pushChunk("ue: sales\r\n\r\n");

        self::assertCount(1, $packets);
        self::assertSame('sales', $packets[0]->getField('Queue'));
    }

    public function testSplitsALineOnlyAtTheFirstColon(): void
    {
        $packets = new AmiPacketParser()->pushChunk("Response: Success\r\nMessage: Interface paused: ok\r\n\r\n");

        self::assertSame('Interface paused: ok', $packets[0]->getField('Message'));
    }

    public function testSkipsAnEmptyPacketBetweenPackets(): void
    {
        $packets = new AmiPacketParser()->pushChunk("Event: QueueCallerJoin\r\n\r\n\r\n\r\nEvent: QueueCallerLeave\r\n\r\n");

        self::assertSame(['QueueCallerJoin', 'QueueCallerLeave'], array_map(static fn(AmiPacket $packet): ?string => $packet->findEventName(), $packets));
    }

    public function testKeepsAPacketSplitInsideItsTerminatorUntilItCompletes(): void
    {
        $parser = new AmiPacketParser();

        self::assertSame([], $parser->pushChunk("Event: QueueCallerJoin\r\nQueue: sales\r\n\r"));

        self::assertSame('sales', $parser->pushChunk("\n")[0]->getField('Queue'));
    }

    public function testParsesAPacketDeliveredOneByteAtATime(): void
    {
        $parser = new AmiPacketParser();
        $packets = [];
        foreach (str_split("Event: QueueCallerJoin\r\nQueue: sales\r\n\r\n") as $byte) {
            $packets = [...$packets, ...$parser->pushChunk($byte)];
        }

        self::assertCount(1, $packets);
        self::assertSame('sales', $packets[0]->getField('Queue'));
    }

    public function testReadsAnEmptyValue(): void
    {
        $packets = new AmiPacketParser()->pushChunk("Event: QueueMemberPause\r\nPausedReason: \r\nReason:\r\n\r\n");

        self::assertSame('', $packets[0]->getField('PausedReason'));
        self::assertSame('', $packets[0]->getField('Reason'));
    }

    public function testReadsAFieldWithoutASpaceAfterTheColon(): void
    {
        $packets = new AmiPacketParser()->pushChunk("Event: QueueCallerJoin\r\nQueue:sales\r\n\r\n");

        self::assertSame('sales', $packets[0]->getField('Queue'));
    }

    public function testSkipsAStrayBlankLineBeforeAPacket(): void
    {
        $packets = new AmiPacketParser()->pushChunk("\r\nEvent: QueueCallerJoin\r\nQueue: sales\r\n\r\n");

        self::assertSame('sales', $packets[0]->getField('Queue'));
    }

    public function testKeepsTheLastValueOfARepeatedKey(): void
    {
        $packets = new AmiPacketParser()->pushChunk("Event: VarSet\r\nVariable: first\r\nVariable: second\r\n\r\n");

        self::assertSame('second', $packets[0]->getField('Variable'));
    }

    public function testThrowsOnALineWithoutColon(): void
    {
        $this->expectException(MalformedAmiPacket::class);

        new AmiPacketParser()->pushChunk("Event: QueueCallerJoin\r\nnonsense\r\n\r\n");
    }

    public function testThrowsWhenAPacketGrowsPastAnyRealOneInsteadOfBufferingForever(): void
    {
        $parser = new AmiPacketParser();
        $parser->pushChunk("Event: QueueCallerJoin\r\n");

        $this->expectException(MalformedAmiPacket::class);

        $parser->pushChunk('Padding: ' . str_repeat('x', 1_100_000));
    }
}
