<?php

declare(strict_types=1);

namespace QueueWallboard\Simulator;

final readonly class SimulationTimings
{
    public function __construct(
        public float $arrivalRatePeriodSeconds,
        public float $minimumPatienceSeconds,
        public float $maximumPatienceSeconds,
        public float $shortHangUpProbability,
        public float $shortHangUpSeconds,
        public float $minimumAnswerSeconds,
        public float $maximumAnswerSeconds,
        public float $ringTimeoutSeconds,
        public float $unansweredRingProbability,
        public float $minimumTalkSeconds,
        public float $maximumTalkSeconds,
        public float $wrapUpSeconds,
        public float $meanSecondsBetweenRandomPauses,
        public float $minimumRandomPauseSeconds,
        public float $maximumRandomPauseSeconds,
        public float $inactivePauseSeconds,
    ) {}

    public static function createDemoTimings(): self
    {
        // demo values, tuned by watching the wallboard until each scenario behaves as intended
        return new self(
            arrivalRatePeriodSeconds: 60.0,
            minimumPatienceSeconds: 30.0,
            maximumPatienceSeconds: 150.0,
            shortHangUpProbability: 0.05,
            shortHangUpSeconds: 5.0,
            minimumAnswerSeconds: 2.0,
            maximumAnswerSeconds: 5.0,
            ringTimeoutSeconds: 15.0,
            unansweredRingProbability: 0.03,
            minimumTalkSeconds: 20.0,
            maximumTalkSeconds: 60.0,
            wrapUpSeconds: 5.0,
            meanSecondsBetweenRandomPauses: 600.0,
            minimumRandomPauseSeconds: 30.0,
            maximumRandomPauseSeconds: 90.0,
            inactivePauseSeconds: 30.0,
        );
    }
}
