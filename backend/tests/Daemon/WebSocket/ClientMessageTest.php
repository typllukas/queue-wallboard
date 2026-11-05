<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Daemon\WebSocket;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QueueWallboard\Daemon\Floor\PauseReason;
use QueueWallboard\Daemon\WebSocket\ClientMessage;
use QueueWallboard\Daemon\WebSocket\InvalidClientMessage;

#[CoversClass(ClientMessage::class)]
final class ClientMessageTest extends TestCase
{
    public function testParsesAPong(): void
    {
        self::assertTrue(ClientMessage::fromJson('{"action":"pong"}')->isPong());
    }

    public function testParsesALunchPause(): void
    {
        $message = ClientMessage::fromJson('{"action":"pause","pause":"lunch"}');

        self::assertTrue($message->isPause());
        self::assertFalse($message->isPong());
        self::assertSame(PauseReason::Lunch, $message->findPauseReason());
    }

    public function testParsesATrainingPause(): void
    {
        self::assertSame(
            PauseReason::Training,
            ClientMessage::fromJson('{"action":"pause","pause":"training"}')->findPauseReason(),
        );
    }

    public function testParsesAnUnpause(): void
    {
        $message = ClientMessage::fromJson('{"action":"unpause"}');

        self::assertTrue($message->isUnpause());
        self::assertNull($message->findPauseReason());
    }

    public function testRejectsMalformedJson(): void
    {
        $this->expectException(InvalidClientMessage::class);

        ClientMessage::fromJson('{"action":');
    }

    public function testRejectsAnUnknownAction(): void
    {
        $this->expectException(InvalidClientMessage::class);

        ClientMessage::fromJson('{"action":"shout"}');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideJsonThatIsNotAnObjectWithAnAction(): array
    {
        return [
            'a string' => ['"pong"'],
            'a number' => ['7'],
            'a list' => ['["pong"]'],
            'no action' => ['{}'],
            'a numeric action' => ['{"action":1}'],
        ];
    }

    #[DataProvider('provideJsonThatIsNotAnObjectWithAnAction')]
    public function testRejectsJsonThatIsNotAnObjectWithAnAction(string $json): void
    {
        $this->expectException(InvalidClientMessage::class);

        ClientMessage::fromJson($json);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideInvalidPauses(): array
    {
        return [
            'inactive is set by the system' => ['{"action":"pause","pause":"inactive"}'],
            'other is what the PBX reports for any other reason' => ['{"action":"pause","pause":"other"}'],
            'unknown reason' => ['{"action":"pause","pause":"nap"}'],
            'missing reason' => ['{"action":"pause"}'],
            'non-string reason' => ['{"action":"pause","pause":1}'],
        ];
    }

    #[DataProvider('provideInvalidPauses')]
    public function testRejectsAPauseOtherThanLunchOrTraining(string $json): void
    {
        $this->expectException(InvalidClientMessage::class);

        ClientMessage::fromJson($json);
    }
}
