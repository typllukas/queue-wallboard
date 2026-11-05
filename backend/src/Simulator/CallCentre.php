<?php

declare(strict_types=1);

namespace QueueWallboard\Simulator;

use DateTimeImmutable;
use Evenement\EventEmitter;
use Psr\Clock\ClockInterface;
use QueueWallboard\Ami\AmiPacket;
use Random\Randomizer;
use React\EventLoop\LoopInterface;

final class CallCentre extends EventEmitter
{
    /** what app_queue writes after a missed ring */
    private const string AUTO_PAUSE_REASON = 'Auto-Pause';
    /** the automatic pause after a missed ring */
    private const string INACTIVE_PAUSE_REASON = 'Inactive';
    /** arbitrary demo reasons */
    private const array RANDOM_PAUSE_REASONS = ['Lunch', 'Training'];

    /** @var array<int|string, SimulatedQueue> */
    private array $queues = [];

    /** @var list<SimulatedAgent> */
    private array $agents = [];

    private Scenario $scenario;
    private int $channelCount = 0;
    private readonly AmiEventFactory $eventFactory;
    private readonly SimulationScheduler $scheduler;

    public function __construct(
        Floor $floor,
        private readonly SimulationTimings $timings,
        LoopInterface $loop,
        private readonly Randomizer $randomizer,
        private readonly ClockInterface $clock,
    ) {
        $this->eventFactory = new AmiEventFactory();
        $this->scheduler = new SimulationScheduler($loop);
        foreach ($floor->queueNames as $queueName) {
            $this->queues[$queueName] = new SimulatedQueue($queueName, $this->scheduler);
        }
        foreach ($floor->agentProfiles as $agentProfile) {
            $this->agents[] = new SimulatedAgent($agentProfile, $clock->now(), $this->scheduler);
        }
    }

    public function start(Scenario $scenario): void
    {
        foreach ($this->agents as $agent) {
            if (!$agent->profile->isPersonControlled) {
                $this->scheduleRandomPause($agent);
            }
        }
        $this->switchScenario($scenario);
    }

    public function stop(): void
    {
        $this->scheduler->cancelAllSteps();
    }

    public function switchScenario(Scenario $scenario): void
    {
        $this->scenario = $scenario;
        foreach ($this->queues as $queue) {
            $this->scheduleArrival($queue);
        }
    }

    public function getScenario(): Scenario
    {
        return $this->scenario;
    }

    public function pauseMember(string $interface, bool $isPaused, ?string $reason): bool
    {
        foreach ($this->agents as $agent) {
            if ($agent->profile->interface !== $interface) {
                continue;
            }
            if ($isPaused) {
                $this->pauseAgent($agent, $reason ?? '');
            } else {
                $this->resumeAgent($agent);
            }

            return true;
        }

        return false;
    }

    /**
     * @return list<AmiPacket>
     */
    public function describeQueueStatus(string $actionId): array
    {
        $statusPackets = [];
        foreach ($this->queues as $queue) {
            $statusPackets[] = $this->eventFactory->createQueueParams($queue, $actionId);
            foreach ($this->agents as $agent) {
                if ($agent->isMemberOf($queue->name)) {
                    $statusPackets[] = $this->eventFactory->createQueueMember($queue->name, $agent, $actionId);
                }
            }
            foreach ($queue->waitingCallers as $callerIndex => $caller) {
                $statusPackets[] = $this->eventFactory->createQueueEntry($caller, $callerIndex + 1, $this->countSecondsSince($caller->joinedAt), $actionId);
            }
        }
        $statusPackets[] = $this->eventFactory->createQueueStatusComplete($actionId, count($statusPackets));

        return $statusPackets;
    }

    private function scheduleArrival(SimulatedQueue $queue): void
    {
        $callsPerSecond = $this->scenario->getCallsPerMinute($queue->name) / $this->timings->arrivalRatePeriodSeconds;
        $queue->scheduleArrival($this->drawExponentialSeconds(1 / $callsPerSecond), function () use ($queue): void {
            $this->admitCaller($queue);
            $this->scheduleArrival($queue);
        });
    }

    private function admitCaller(SimulatedQueue $queue): void
    {
        $channelNumber = ++$this->channelCount;
        $caller = new SimulatedCaller(
            $this->formatUniqueId($channelNumber),
            sprintf('PJSIP/trunk-%08x', $channelNumber),
            sprintf('+420%09d', $this->randomizer->getInt(600000000, 799999999)),
            $queue->name,
            $this->clock->now(),
            count($queue->waitingCallers) + 1,
            $this->scheduler,
        );
        $queue->addCaller($caller);
        $this->emitPacket($this->eventFactory->createQueueCallerJoin($caller, $caller->originalPosition, count($queue->waitingCallers)));

        $patienceSeconds = $this->randomizer->nextFloat() < $this->timings->shortHangUpProbability
            ? $this->randomizer->getFloat(0, $this->timings->shortHangUpSeconds)
            : $this->randomizer->getFloat($this->timings->minimumPatienceSeconds, $this->timings->maximumPatienceSeconds);
        $caller->schedulePatienceEnd($patienceSeconds, fn() => $this->abandonQueue($queue, $caller));

        $this->offerCalls();
    }

    private function offerCalls(): void
    {
        $waitingCallers = [];
        foreach ($this->queues as $queue) {
            array_push($waitingCallers, ...$queue->waitingCallers);
        }
        usort($waitingCallers, static fn(SimulatedCaller $firstCaller, SimulatedCaller $secondCaller): int => $firstCaller->joinedAt <=> $secondCaller->joinedAt);

        foreach ($waitingCallers as $caller) {
            if ($caller->isRinging()) {
                continue;
            }
            $longestIdleAgent = null;
            foreach ($this->agents as $agent) {
                if ($agent->isFree() && $agent->isMemberOf($caller->queueName)
                    && ($longestIdleAgent === null || $agent->freeSince < $longestIdleAgent->freeSince)
                ) {
                    $longestIdleAgent = $agent;
                }
            }
            if ($longestIdleAgent !== null) {
                $this->ringAgent($caller, $longestIdleAgent);
            }
        }
    }

    private function ringAgent(SimulatedCaller $caller, SimulatedAgent $agent): void
    {
        $channelNumber = ++$this->channelCount;
        $agent->startRinging(
            $caller,
            $this->formatUniqueId($channelNumber),
            sprintf('%s-%08x', $agent->profile->interface, $channelNumber),
        );
        $this->emitPacket($this->eventFactory->createAgentCalled($caller, $agent));
        $this->emitMemberStatus($agent);

        if (!$agent->profile->isPersonControlled && $this->randomizer->nextFloat() < $this->timings->unansweredRingProbability) {
            $caller->scheduleRingEnd($this->timings->ringTimeoutSeconds, fn() => $this->missRing($caller, $agent));

            return;
        }
        $answerSeconds = $this->randomizer->getFloat($this->timings->minimumAnswerSeconds, $this->timings->maximumAnswerSeconds);
        $caller->scheduleRingEnd($answerSeconds, fn() => $this->connectCall($caller, $agent, intval($answerSeconds)));
    }

    private function missRing(SimulatedCaller $caller, SimulatedAgent $agent): void
    {
        $ringMilliseconds = intval(round($this->timings->ringTimeoutSeconds * 1000));
        $this->emitPacket($this->eventFactory->createAgentRingNoAnswer($caller, $agent, $ringMilliseconds));
        $agent->stopRinging();
        $this->pauseAgent($agent, self::AUTO_PAUSE_REASON);
        $this->emitMemberStatus($agent);
        $this->offerCalls();
    }

    private function connectCall(SimulatedCaller $caller, SimulatedAgent $agent, int $ringSeconds): void
    {
        $queue = $this->queues[$caller->queueName];
        $position = $queue->getPosition($caller);
        $caller->cancelSteps();
        $queue->removeCaller($caller);
        $this->emitPacket($this->eventFactory->createQueueCallerLeave($caller, $position, count($queue->waitingCallers)));

        $holdSeconds = $this->countSecondsSince($caller->joinedAt);
        $queue->recordAnswer($holdSeconds);
        $agent->connect($caller);
        $this->emitPacket($this->eventFactory->createAgentConnect($caller, $agent, $holdSeconds, $ringSeconds));
        $this->emitMemberStatus($agent);

        $talkSeconds = $this->randomizer->getFloat($this->timings->minimumTalkSeconds, $this->timings->maximumTalkSeconds);
        $this->scheduler->scheduleStep($talkSeconds, fn() => $this->completeCall($queue, $caller, $agent, $holdSeconds, intval($talkSeconds)));
    }

    private function completeCall(SimulatedQueue $queue, SimulatedCaller $caller, SimulatedAgent $agent, int $holdSeconds, int $talkSeconds): void
    {
        $this->emitPacket($this->eventFactory->createAgentComplete($caller, $agent, $holdSeconds, $talkSeconds));
        $queue->recordCompletedCall($talkSeconds);
        $agent->completeCall($this->clock->now());
        $this->emitMemberStatus($agent);

        $this->scheduler->scheduleStep($this->timings->wrapUpSeconds, function () use ($agent): void {
            $agent->finishWrapUp($this->clock->now());
            $this->offerCalls();
        });
    }

    private function abandonQueue(SimulatedQueue $queue, SimulatedCaller $caller): void
    {
        $caller->cancelSteps();
        $position = $queue->getPosition($caller);
        $this->emitPacket($this->eventFactory->createQueueCallerAbandon($caller, $position, $this->countSecondsSince($caller->joinedAt)));
        $queue->removeCaller($caller);
        $queue->recordAbandon();
        $this->emitPacket($this->eventFactory->createQueueCallerLeave($caller, $position, count($queue->waitingCallers)));

        foreach ($this->agents as $agent) {
            if ($agent->ringingCaller === $caller) {
                $agent->stopRinging();
                $this->emitMemberStatus($agent);
            }
        }
        $this->offerCalls();
    }

    private function pauseAgent(SimulatedAgent $agent, string $reason): void
    {
        $agent->pause($reason, $this->clock->now());
        $this->emitMemberPause($agent);
        if ($agent->profile->isPersonControlled) {
            return;
        }
        $pauseSeconds = in_array($reason, [self::AUTO_PAUSE_REASON, self::INACTIVE_PAUSE_REASON], true)
            ? $this->timings->inactivePauseSeconds
            : $this->randomizer->getFloat($this->timings->minimumRandomPauseSeconds, $this->timings->maximumRandomPauseSeconds);
        $agent->schedulePauseChange($pauseSeconds, fn() => $this->resumeAgent($agent));
    }

    private function resumeAgent(SimulatedAgent $agent): void
    {
        $agent->resume();
        $this->emitMemberPause($agent);
        if (!$agent->profile->isPersonControlled) {
            $this->scheduleRandomPause($agent);
        }
        $this->offerCalls();
    }

    private function scheduleRandomPause(SimulatedAgent $agent): void
    {
        $agent->schedulePauseChange(
            $this->drawExponentialSeconds($this->timings->meanSecondsBetweenRandomPauses),
            fn() => $this->pauseAgent($agent, self::RANDOM_PAUSE_REASONS[$this->randomizer->pickArrayKeys(self::RANDOM_PAUSE_REASONS, 1)[0]]),
        );
    }

    private function emitMemberStatus(SimulatedAgent $agent): void
    {
        foreach ($agent->profile->queueNames as $queueName) {
            $this->emitPacket($this->eventFactory->createQueueMemberStatus($queueName, $agent));
        }
    }

    private function emitMemberPause(SimulatedAgent $agent): void
    {
        foreach ($agent->profile->queueNames as $queueName) {
            $this->emitPacket($this->eventFactory->createQueueMemberPause($queueName, $agent));
        }
    }

    private function emitPacket(AmiPacket $packet): void
    {
        $this->emit('event', [$packet]);
    }

    private function drawExponentialSeconds(float $meanSeconds): float
    {
        return -log(1 - $this->randomizer->nextFloat()) * $meanSeconds;
    }

    private function formatUniqueId(int $channelNumber): string
    {
        return sprintf('%d.%d', $this->clock->now()->getTimestamp(), $channelNumber);
    }

    private function countSecondsSince(DateTimeImmutable $since): int
    {
        return $this->clock->now()->getTimestamp() - $since->getTimestamp();
    }
}
