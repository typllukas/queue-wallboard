<?php

declare(strict_types=1);

namespace QueueWallboard\Daemon\Floor;

use DateTimeImmutable;

final class QueueCounters
{
    /** a caller who hangs up sooner is left out of SLA and SVL */
    public const int SKIP_SECONDS = 5;
    /** a wallboard setting, not a queue one, as in the reference platform */
    public const int SLA_THRESHOLD_SECONDS = 20;

    private int $answeredCount = 0;
    private int $answeredWithinThresholdCount = 0;
    private int $unansweredCount = 0;
    private int $abandonedWithinSkipCount = 0;
    private int $abandonedAfterSkipWithinThresholdCount = 0;

    public function __construct(public readonly DateTimeImmutable $countedSince) {}

    public function recordAnswer(int $holdSeconds): void
    {
        ++$this->answeredCount;
        if ($holdSeconds <= self::SLA_THRESHOLD_SECONDS) {
            ++$this->answeredWithinThresholdCount;
        }
    }

    public function recordAbandon(int $holdSeconds): void
    {
        ++$this->unansweredCount;
        if ($holdSeconds < self::SKIP_SECONDS) {
            ++$this->abandonedWithinSkipCount;

            return;
        }
        if ($holdSeconds <= self::SLA_THRESHOLD_SECONDS) {
            ++$this->abandonedAfterSkipWithinThresholdCount;
        }
    }

    public function countAnswered(): int
    {
        return $this->answeredCount;
    }

    public function countUnanswered(): int
    {
        return $this->unansweredCount;
    }

    public function countTotal(): int
    {
        return $this->answeredCount + $this->unansweredCount;
    }

    public function calculateAbandonRate(): ?float
    {
        if ($this->countTotal() === 0) {
            return null;
        }

        return $this->unansweredCount / $this->countTotal();
    }

    public function calculateServiceLevel(): ?float
    {
        $countedCallCount = $this->countTotal() - $this->abandonedWithinSkipCount - $this->abandonedAfterSkipWithinThresholdCount;
        if ($countedCallCount === 0) {
            return null;
        }

        return $this->answeredWithinThresholdCount / $countedCallCount;
    }

    public function calculateServiceAvailability(): ?float
    {
        $countedCallCount = $this->countTotal() - $this->abandonedWithinSkipCount;
        if ($countedCallCount === 0) {
            return null;
        }

        return $this->answeredCount / $countedCallCount;
    }
}
