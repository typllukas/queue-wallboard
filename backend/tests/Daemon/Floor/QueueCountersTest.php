<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Daemon\Floor;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueueWallboard\Daemon\Floor\QueueCounters;

#[CoversClass(QueueCounters::class)]
final class QueueCountersTest extends TestCase
{
    public function testServiceLevelsFollowTheSkipAndThresholdRules(): void
    {
        $counters = $this->createCounters();
        foreach ([3, 8, 12, 15, 18, 20, 25, 40] as $holdSeconds) {
            $counters->recordAnswer($holdSeconds);
        }
        foreach ([3, 12, 40, 70] as $holdSeconds) {
            $counters->recordAbandon($holdSeconds);
        }

        self::assertSame(8, $counters->countAnswered());
        self::assertSame(4, $counters->countUnanswered());
        self::assertSame(12, $counters->countTotal());
        self::assertEqualsWithDelta(4 / 12, $counters->calculateAbandonRate(), 0.0001);
        self::assertEqualsWithDelta(8 / 11, $counters->calculateServiceAvailability(), 0.0001);
        self::assertEqualsWithDelta(6 / 10, $counters->calculateServiceLevel(), 0.0001);
    }

    public function testAnAbandonAtExactlyTheSkipLimitIsNotSkipped(): void
    {
        $counters = $this->createCounters();
        $counters->recordAnswer(10);
        $counters->recordAbandon(QueueCounters::SKIP_SECONDS);

        self::assertEqualsWithDelta(1 / 2, $counters->calculateServiceAvailability(), 0.0001);
        self::assertEqualsWithDelta(1, $counters->calculateServiceLevel(), 0.0001);
    }

    public function testAnAbandonAtTheThresholdIsWithinItAndOneSecondLaterIsNot(): void
    {
        $counters = $this->createCounters();
        $counters->recordAnswer(1);
        $counters->recordAbandon(QueueCounters::SLA_THRESHOLD_SECONDS);
        $counters->recordAbandon(QueueCounters::SLA_THRESHOLD_SECONDS + 1);

        self::assertSame(1 / 2, $counters->calculateServiceLevel());
    }

    public function testServiceLevelsAreNullWithNoFinishedCall(): void
    {
        $counters = $this->createCounters();

        self::assertNull($counters->calculateAbandonRate());
        self::assertNull($counters->calculateServiceAvailability());
        self::assertNull($counters->calculateServiceLevel());
    }

    private function createCounters(): QueueCounters
    {
        return new QueueCounters(new DateTimeImmutable('2026-11-04 10:00:00'));
    }
}
