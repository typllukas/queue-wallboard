<?php

declare(strict_types=1);

namespace QueueWallboard\Simulator;

use DateTimeImmutable;

final class SimulatedCaller
{
    private ?int $patienceStepId = null;
    private ?int $ringStepId = null;

    public function __construct(
        public readonly string $uniqueId,
        public readonly string $channelName,
        public readonly string $callerNumber,
        public readonly string $queueName,
        public readonly DateTimeImmutable $joinedAt,
        public readonly int $originalPosition,
        private readonly SimulationScheduler $scheduler,
    ) {}

    public function isRinging(): bool
    {
        return $this->ringStepId !== null;
    }

    /**
     * @param callable(): void $abandonQueue
     */
    public function schedulePatienceEnd(float $patienceSeconds, callable $abandonQueue): void
    {
        $this->patienceStepId = $this->scheduler->scheduleStep($patienceSeconds, function () use ($abandonQueue): void {
            $this->patienceStepId = null;
            $abandonQueue();
        });
    }

    /**
     * @param callable(): void $endRing
     */
    public function scheduleRingEnd(float $ringSeconds, callable $endRing): void
    {
        $this->ringStepId = $this->scheduler->scheduleStep($ringSeconds, function () use ($endRing): void {
            $this->ringStepId = null;
            $endRing();
        });
    }

    public function cancelSteps(): void
    {
        foreach ([$this->patienceStepId, $this->ringStepId] as $stepId) {
            if ($stepId !== null) {
                $this->scheduler->cancelStep($stepId);
            }
        }
        $this->patienceStepId = null;
        $this->ringStepId = null;
    }
}
