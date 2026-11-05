<?php

declare(strict_types=1);

namespace QueueWallboard\Simulator;

use LogicException;

final class SimulatedQueue
{
    /** @var list<SimulatedCaller> */
    public private(set) array $waitingCallers = [];
    public private(set) int $completedCallCount = 0;
    public private(set) int $abandonedCallCount = 0;
    public private(set) int $averageHoldSeconds = 0;
    public private(set) int $averageTalkSeconds = 0;
    private ?int $arrivalStepId = null;

    public function __construct(
        public readonly string $name,
        private readonly SimulationScheduler $scheduler,
    ) {}

    public function addCaller(SimulatedCaller $caller): void
    {
        $this->waitingCallers[] = $caller;
    }

    public function removeCaller(SimulatedCaller $caller): void
    {
        $this->waitingCallers = array_values(array_filter(
            $this->waitingCallers,
            static fn(SimulatedCaller $waitingCaller): bool => $waitingCaller !== $caller,
        ));
    }

    public function getPosition(SimulatedCaller $caller): int
    {
        $callerIndex = array_search($caller, $this->waitingCallers, true);
        if ($callerIndex === false) {
            throw new LogicException(sprintf('Caller "%s" is not waiting in queue "%s".', $caller->uniqueId, $this->name));
        }

        return $callerIndex + 1;
    }

    public function recordAnswer(int $holdSeconds): void
    {
        $this->averageHoldSeconds = $this->calculateWeightedAverage($this->averageHoldSeconds, $holdSeconds);
    }

    public function recordCompletedCall(int $talkSeconds): void
    {
        $this->completedCallCount++;
        $this->averageTalkSeconds = $this->calculateWeightedAverage($this->averageTalkSeconds, $talkSeconds);
    }

    public function recordAbandon(): void
    {
        $this->abandonedCallCount++;
    }

    /**
     * @param callable(): void $admitCaller
     */
    public function scheduleArrival(float $gapSeconds, callable $admitCaller): void
    {
        if ($this->arrivalStepId !== null) {
            $this->scheduler->cancelStep($this->arrivalStepId);
        }
        $this->arrivalStepId = $this->scheduler->scheduleStep($gapSeconds, function () use ($admitCaller): void {
            $this->arrivalStepId = null;
            $admitCaller();
        });
    }

    private function calculateWeightedAverage(int $average, int $newSeconds): int
    {
        // app_queue's own formula for Holdtime and TalkTime
        return intdiv($average * 3 + $newSeconds, 4);
    }
}
