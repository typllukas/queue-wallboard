<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Daemon\LongPoll;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QueueWallboard\Daemon\Floor\FloorChanges;
use QueueWallboard\Daemon\LongPoll\ChangeHistory;

#[CoversClass(ChangeHistory::class)]
final class ChangeHistoryTest extends TestCase
{
    private const string BOOT_ID = '5f0c2a9e';

    public function testAFreshHistoryStartsAtVersionZeroWithNothingChanged(): void
    {
        $history = new ChangeHistory(self::BOOT_ID, 3);

        self::assertSame('5f0c2a9e-0', $history->getCurrentHash());
        self::assertEquals(new FloorChanges([], [], false, false), $history->findChangesSince('5f0c2a9e-0'));
    }

    public function testEachRecordedChangeGetsTheNextVersion(): void
    {
        $history = new ChangeHistory(self::BOOT_ID, 3);

        $history->recordChanges(new FloorChanges(['201'], [], false, false));
        self::assertSame('5f0c2a9e-1', $history->getCurrentHash());
        $history->recordChanges(new FloorChanges([], ['sales'], false, false));
        self::assertSame('5f0c2a9e-2', $history->getCurrentHash());
    }

    public function testAnEmptyChangeKeepsTheHash(): void
    {
        $history = new ChangeHistory(self::BOOT_ID, 3);
        $history->recordChanges(new FloorChanges(['201'], [], false, false));

        $history->recordChanges(new FloorChanges([], [], false, false));
        self::assertSame('5f0c2a9e-1', $history->getCurrentHash());
        self::assertEquals(new FloorChanges([], [], false, false), $history->findChangesSince('5f0c2a9e-1'));
    }

    public function testChangesSinceAHashAreTheUnionOfEveryLaterChange(): void
    {
        $history = new ChangeHistory(self::BOOT_ID, 3);
        $history->recordChanges(new FloorChanges(['201'], ['sales'], false, false));
        $history->recordChanges(new FloorChanges(['203', '201'], ['support'], false, false));
        $history->recordChanges(new FloorChanges(['205'], ['sales'], true, false));

        self::assertEquals(
            new FloorChanges(['203', '201', '205'], ['support', 'sales'], true, false),
            $history->findChangesSince('5f0c2a9e-1'),
        );
    }

    public function testHistoryForgetsBeyondItsLength(): void
    {
        $history = new ChangeHistory(self::BOOT_ID, 2);
        $history->recordChanges(new FloorChanges(['201'], [], false, false));
        $history->recordChanges(new FloorChanges(['203'], [], false, false));
        $history->recordChanges(new FloorChanges(['205'], [], false, false));

        self::assertNull($history->findChangesSince('5f0c2a9e-0'));
        self::assertEquals(new FloorChanges(['203', '205'], [], false, false), $history->findChangesSince('5f0c2a9e-1'));
    }

    public function testAReplacedLiveStateMakesEveryEarlierHashUnknown(): void
    {
        $history = new ChangeHistory(self::BOOT_ID, 3);
        $history->recordChanges(new FloorChanges(['205'], [], false, false));

        $history->recordChanges(new FloorChanges(['203'], ['sales'], false, true));
        self::assertSame('5f0c2a9e-2', $history->getCurrentHash());
        $history->recordChanges(new FloorChanges(['201'], [], false, false));

        self::assertNull($history->findChangesSince('5f0c2a9e-0'));
        self::assertNull($history->findChangesSince('5f0c2a9e-1'));
        self::assertEquals(new FloorChanges(['201'], [], false, false), $history->findChangesSince('5f0c2a9e-2'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideUnknownHashes(): array
    {
        return [
            'another boot' => ['0d1e2f3a-1'],
            'ahead of the history' => ['5f0c2a9e-2'],
            'no version' => ['5f0c2a9e-'],
            'a version that is not a number' => ['5f0c2a9e-one'],
            'a negative version' => ['5f0c2a9e--1'],
            'empty' => [''],
        ];
    }

    #[DataProvider('provideUnknownHashes')]
    public function testChangesSinceAnUnknownHashAreNotFound(string $hash): void
    {
        $history = new ChangeHistory(self::BOOT_ID, 3);
        $history->recordChanges(new FloorChanges(['201'], [], false, false));

        self::assertNull($history->findChangesSince($hash));
    }
}
