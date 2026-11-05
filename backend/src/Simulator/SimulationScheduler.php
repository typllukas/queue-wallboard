<?php

declare(strict_types=1);

namespace QueueWallboard\Simulator;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

final class SimulationScheduler
{
    /** @var array<int, array{float, callable(): void}> */
    private array $pendingSteps = [];
    private int $lastStepId = 0;
    private ?float $currentStepDueSeconds = null;
    private ?TimerInterface $wakeUpTimer = null;
    private readonly int $startedAtNanoseconds;

    public function __construct(private readonly LoopInterface $loop)
    {
        $this->startedAtNanoseconds = hrtime(true);
    }

    /**
     * @param callable(): void $takeStep
     */
    public function scheduleStep(float $delaySeconds, callable $takeStep): int
    {
        $stepId = ++$this->lastStepId;
        // inside a step, count from its due time, so a late timer does not shift the steps after it
        $this->pendingSteps[$stepId] = [($this->currentStepDueSeconds ?? $this->measureElapsedSeconds()) + $delaySeconds, $takeStep];
        $this->wakeUpForNextStep();

        return $stepId;
    }

    public function cancelStep(int $stepId): void
    {
        unset($this->pendingSteps[$stepId]);
    }

    public function cancelAllSteps(): void
    {
        $this->pendingSteps = [];
        if ($this->wakeUpTimer !== null) {
            $this->loop->cancelTimer($this->wakeUpTimer);
        }
        $this->wakeUpTimer = null;
    }

    private function takeDueSteps(): void
    {
        $this->wakeUpTimer = null;
        $elapsedSeconds = $this->measureElapsedSeconds();
        while (($nextStepId = $this->findNextStepId()) !== null && $this->pendingSteps[$nextStepId][0] <= $elapsedSeconds) {
            [$this->currentStepDueSeconds, $takeStep] = $this->pendingSteps[$nextStepId];
            unset($this->pendingSteps[$nextStepId]);
            $takeStep();
        }
        $this->currentStepDueSeconds = null;
        $this->wakeUpForNextStep();
    }

    private function wakeUpForNextStep(): void
    {
        if ($this->currentStepDueSeconds !== null) {
            return;
        }
        if ($this->wakeUpTimer !== null) {
            $this->loop->cancelTimer($this->wakeUpTimer);
            $this->wakeUpTimer = null;
        }
        $nextStepId = $this->findNextStepId();
        if ($nextStepId === null) {
            return;
        }
        $delaySeconds = max(0.0, $this->pendingSteps[$nextStepId][0] - $this->measureElapsedSeconds());
        $this->wakeUpTimer = $this->loop->addTimer($delaySeconds, fn() => $this->takeDueSteps());
    }

    private function findNextStepId(): ?int
    {
        $nextStepId = null;
        foreach ($this->pendingSteps as $stepId => [$dueSeconds]) {
            if ($nextStepId === null || $dueSeconds < $this->pendingSteps[$nextStepId][0]) {
                $nextStepId = $stepId;
            }
        }

        return $nextStepId;
    }

    private function measureElapsedSeconds(): float
    {
        return (hrtime(true) - $this->startedAtNanoseconds) / 1e9;
    }
}
