<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Daemon\Floor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueueWallboard\Daemon\Floor\PauseReason;

#[CoversClass(PauseReason::class)]
final class PauseReasonTest extends TestCase
{
    public function testAutoPauseMapsToInactive(): void
    {
        self::assertSame(PauseReason::Inactive, PauseReason::fromAmiReason('Auto-Pause'));
    }

    public function testEveryReasonSurvivesTheAmiRoundTrip(): void
    {
        foreach (PauseReason::cases() as $reason) {
            self::assertSame($reason, PauseReason::fromAmiReason($reason->toAmiReason()));
        }
    }

    public function testAnyOtherOrEmptyReasonIsTheOtherPause(): void
    {
        self::assertSame(PauseReason::Other, PauseReason::fromAmiReason('Coffee'));
        self::assertSame(PauseReason::Other, PauseReason::fromAmiReason(''));
    }
}
