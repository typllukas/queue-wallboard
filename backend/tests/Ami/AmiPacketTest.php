<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Ami;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QueueWallboard\Ami\AmiPacket;
use QueueWallboard\Ami\AmiPacketParser;
use QueueWallboard\Ami\MalformedAmiPacket;
use QueueWallboard\Ami\MissingAmiField;

#[CoversClass(AmiPacket::class)]
final class AmiPacketTest extends TestCase
{
    public function testRoundTripsThroughToWire(): void
    {
        $wire = "Action: QueuePause\r\nActionID: 7\r\nPaused: true\r\n\r\n";

        $packets = new AmiPacketParser()->pushChunk($wire);

        self::assertSame($wire, $packets[0]->toWire());
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function provideFieldsWithALineBreak(): array
    {
        return [
            'in a value' => [['Interface' => "PJSIP/201\r\n\r\nAction: Originate"]],
            'in a name' => [["Paused\n" => 'true']],
        ];
    }

    /**
     * @param array<string, string> $fields
     */
    #[DataProvider('provideFieldsWithALineBreak')]
    public function testRefusesALineBreakInAFieldSoNoSecondActionCanBeWritten(array $fields): void
    {
        $this->expectException(MalformedAmiPacket::class);

        new AmiPacket(['Action' => 'QueuePause', ...$fields])->toWire();
    }

    public function testThrowsOnAMissingField(): void
    {
        $this->expectException(MissingAmiField::class);

        new AmiPacket(['Event' => 'QueueCallerJoin'])->getField('Queue');
    }

    public function testReadsAWholeNumberField(): void
    {
        self::assertSame(42, new AmiPacket(['HoldTime' => '42'])->getIntegerField('HoldTime'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideNonIntegerValues(): array
    {
        return [
            'text' => ['abc'],
            'empty' => [''],
            'negative' => ['-5'],
            'decimal' => ['4.5'],
        ];
    }

    #[DataProvider('provideNonIntegerValues')]
    public function testRefusesAFieldThatIsNotAWholeNumber(string $value): void
    {
        $this->expectException(MalformedAmiPacket::class);

        new AmiPacket(['HoldTime' => $value])->getIntegerField('HoldTime');
    }

    public function testFindsOptionalFieldsAndClassifiesResponses(): void
    {
        $success = new AmiPacket(['Response' => 'Success', 'ActionID' => '3']);
        $error = new AmiPacket(['Response' => 'Error']);
        $event = new AmiPacket(['Event' => 'QueueMember']);

        self::assertSame('3', $success->findActionId());
        self::assertNull($error->findActionId());
        self::assertTrue($success->isSuccessResponse());
        self::assertFalse($error->isSuccessResponse());
        self::assertFalse($event->isSuccessResponse());
        self::assertSame('QueueMember', $event->findEventName());
        self::assertNull($success->findEventName());
        self::assertNull($success->findField('Queue'));
    }
}
