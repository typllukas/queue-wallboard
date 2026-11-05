<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Simulator;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueueWallboard\Ami\AmiPacket;
use QueueWallboard\Simulator\AgentProfile;
use QueueWallboard\Simulator\CallCentre;
use QueueWallboard\Simulator\Floor;
use QueueWallboard\Simulator\Scenario;
use QueueWallboard\Simulator\SimulationTimings;
use Random\Engine\Mt19937;
use Random\Randomizer;
use React\EventLoop\Loop;
use Symfony\Component\Clock\MockClock;

use function React\Async\delay;

#[CoversClass(CallCentre::class)]
final class CallCentreTest extends TestCase
{
    private const float INACTIVE_PAUSE_SECONDS = 0.03;
    private const int COMPARED_EVENT_COUNT = 200;
    private const float WAIT_STEP_SECONDS = 0.001;

    /** @var list<CallCentre> */
    private array $callCentres = [];

    /** @var list<AmiPacket> */
    private array $packets = [];

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->callCentres as $callCentre) {
            $callCentre->stop();
        }
    }

    public function testACallerWhoWaitsIsAnsweredByAFreeAgent(): void
    {
        $this->createCallCentre(Floor::createDemoFloor(), $this->createTimings())->start(Scenario::Quiet);

        $this->runLoopUntil(fn(): bool => $this->filterPackets('AgentComplete') !== []);

        $uniqueId = $this->filterPackets('AgentComplete')[0]->getField('Uniqueid');
        $callerEventNames = [];
        foreach ($this->packets as $packet) {
            if ($packet->findField('Uniqueid') === $uniqueId) {
                $callerEventNames[] = $packet->findEventName();
            }
        }
        self::assertSame(['QueueCallerJoin', 'AgentCalled', 'QueueCallerLeave', 'AgentConnect', 'AgentComplete'], $callerEventNames);
    }

    public function testAMissedRingPausesTheAgentInEveryQueue(): void
    {
        $this->createCallCentre($this->createFloorOfOneAgent(false), $this->createTimings(unansweredRingProbability: 1.0))
            ->start(Scenario::Quiet);

        $this->runLoopUntil(fn(): bool => count($this->filterPackets('QueueMemberPause')) >= 2);

        $firstRingAndItsPauses = array_slice(array_values(array_filter(
            $this->packets,
            static fn(AmiPacket $packet): bool => in_array($packet->findEventName(), ['AgentCalled', 'AgentRingNoAnswer', 'QueueMemberPause'], true),
        )), 0, 4);
        self::assertSame(
            ['AgentCalled', 'AgentRingNoAnswer', 'QueueMemberPause', 'QueueMemberPause'],
            array_map(static fn(AmiPacket $packet): ?string => $packet->findEventName(), $firstRingAndItsPauses),
        );
        self::assertSame('PJSIP/204', $firstRingAndItsPauses[1]->getField('Interface'));
        foreach ([2 => 'sales', 3 => 'support'] as $packetIndex => $queueName) {
            self::assertSame($queueName, $firstRingAndItsPauses[$packetIndex]->getField('Queue'));
            self::assertSame('1', $firstRingAndItsPauses[$packetIndex]->getField('Paused'));
            self::assertSame('Auto-Pause', $firstRingAndItsPauses[$packetIndex]->getField('PausedReason'));
        }
    }

    public function testPersonControlledAgentNeverMissesARing(): void
    {
        $this->createCallCentre($this->createFloorOfOneAgent(true), $this->createTimings(unansweredRingProbability: 1.0))
            ->start(Scenario::Quiet);

        $this->runLoopUntil(fn(): bool => count($this->filterPackets('AgentConnect')) >= 1);

        self::assertCount(1, $this->filterPackets('AgentConnect'));
        self::assertSame([], $this->filterPackets('AgentRingNoAnswer'));
        self::assertSame([], $this->filterPackets('QueueMemberPause'));
    }

    public function testPersonControlledAgentStaysInactiveUntilResumed(): void
    {
        $callCentre = $this->createCallCentre($this->createFloorOfOneAgent(true), $this->createTimings());
        $callCentre->start(Scenario::Quiet);

        self::assertTrue($callCentre->pauseMember('PJSIP/203', true, 'Inactive'));
        $this->runLoopFor(self::INACTIVE_PAUSE_SECONDS * 3);

        self::assertSame([], $this->filterResumes());
        self::assertSame([], $this->filterPackets('AgentCalled'));

        self::assertTrue($callCentre->pauseMember('PJSIP/203', false, null));

        self::assertSame(['sales', 'support'], array_map(
            static fn(AmiPacket $packet): string => $packet->getField('Queue'),
            $this->filterResumes(),
        ));
        $this->runLoopUntil(fn(): bool => count($this->filterPackets('AgentCalled')) >= 1);
        self::assertCount(1, $this->filterPackets('AgentCalled'));
    }

    public function testAutomaticAgentResumesFromAnAutoPauseAfterTheInactivePause(): void
    {
        $this->createCallCentre($this->createFloorOfOneAgent(false), $this->createTimings(unansweredRingProbability: 1.0))
            ->start(Scenario::Quiet);

        $this->runLoopUntil(fn(): bool => count($this->filterPackets('QueueMemberPause')) >= 2);
        $this->runLoopFor(self::INACTIVE_PAUSE_SECONDS / 2);

        self::assertSame([], $this->filterResumes());

        $this->runLoopUntil(fn(): bool => count($this->filterResumes()) >= 2, self::INACTIVE_PAUSE_SECONDS * 2);

        self::assertSame(['sales', 'support'], array_map(
            static fn(AmiPacket $packet): string => $packet->getField('Queue'),
            array_slice($this->filterResumes(), 0, 2),
        ));
    }

    public function testPauseMemberEmitsOnePausePerQueue(): void
    {
        $callCentre = $this->createCallCentre(Floor::createDemoFloor(), $this->createTimings());

        self::assertTrue($callCentre->pauseMember('PJSIP/203', true, 'Lunch'));

        self::assertCount(2, $this->packets);
        foreach (['sales', 'support'] as $packetIndex => $queueName) {
            self::assertSame('QueueMemberPause', $this->packets[$packetIndex]->findEventName());
            self::assertSame($queueName, $this->packets[$packetIndex]->getField('Queue'));
            self::assertSame('PJSIP/203', $this->packets[$packetIndex]->getField('Interface'));
            self::assertSame('1', $this->packets[$packetIndex]->getField('Paused'));
            self::assertSame('Lunch', $this->packets[$packetIndex]->getField('PausedReason'));
        }
    }

    public function testPauseMemberRejectsAnUnknownInterface(): void
    {
        $callCentre = $this->createCallCentre(Floor::createDemoFloor(), $this->createTimings());

        self::assertFalse($callCentre->pauseMember('PJSIP/299', true, 'Lunch'));
        self::assertSame([], $this->packets);
    }

    public function testQueueStatusDescribesQueuesMembersAndWaitingCallers(): void
    {
        $callCentre = $this->createCallCentre($this->createFloorOfOneAgent(true), $this->createTimings());
        $callCentre->pauseMember('PJSIP/203', true, 'Lunch');
        $callCentre->start(Scenario::Peak);
        $this->runLoopUntil(fn(): bool => $this->countWaitingCallers('sales') > 0 && $this->countWaitingCallers('support') > 1);

        $statusPackets = $callCentre->describeQueueStatus('status-7');

        $expectedEventNames = [];
        foreach (['sales', 'support'] as $queueName) {
            $expectedEventNames[] = 'QueueParams';
            $expectedEventNames[] = 'QueueMember';
            array_push($expectedEventNames, ...array_fill(0, $this->countWaitingCallers($queueName), 'QueueEntry'));
        }
        $expectedEventNames[] = 'QueueStatusComplete';
        self::assertSame($expectedEventNames, array_map(
            static fn(AmiPacket $packet): ?string => $packet->findEventName(),
            $statusPackets,
        ));
        foreach ($statusPackets as $statusPacket) {
            self::assertSame('status-7', $statusPacket->findActionId());
        }
        $statusComplete = $statusPackets[count($statusPackets) - 1];
        self::assertSame('Complete', $statusComplete->getField('EventList'));
        self::assertSame(strval(count($statusPackets) - 1), $statusComplete->getField('ListItems'));
    }

    public function testSameSeedGivesTheSameEventSequence(): void
    {
        $timings = $this->createTimings(
            unansweredRingProbability: 0.03,
            shortHangUpProbability: 0.05,
            meanSecondsBetweenRandomPauses: 0.6,
        );
        $eventSequences = [];
        foreach ([1, 2] as $runNumber) {
            $firstPacketIndex = count($this->packets);
            $callCentre = $this->createCallCentre(Floor::createDemoFloor(), $timings);
            $callCentre->start(Scenario::Peak);
            $this->runLoopUntil(fn(): bool => count($this->packets) >= $firstPacketIndex + self::COMPARED_EVENT_COUNT);
            $callCentre->stop();
            $eventSequences[$runNumber] = array_map(
                static fn(AmiPacket $packet): string => $packet->toWire(),
                array_slice($this->packets, $firstPacketIndex, self::COMPARED_EVENT_COUNT),
            );
        }

        self::assertCount(self::COMPARED_EVENT_COUNT, $eventSequences[1]);
        self::assertSame($eventSequences[1], $eventSequences[2]);
    }

    private function createCallCentre(Floor $floor, SimulationTimings $timings): CallCentre
    {
        $callCentre = new CallCentre(
            $floor,
            $timings,
            Loop::get(),
            new Randomizer(new Mt19937(42)),
            new MockClock('2026-11-03 10:00:00'),
        );
        $callCentre->on('event', function (AmiPacket $packet): void {
            $this->packets[] = $packet;
        });
        $this->callCentres[] = $callCentre;

        return $callCentre;
    }

    private function createFloorOfOneAgent(bool $isPersonControlled): Floor
    {
        $extension = $isPersonControlled ? '203' : '204';

        return new Floor(
            [Floor::SALES_QUEUE, Floor::SUPPORT_QUEUE],
            [new AgentProfile($extension, 'Agent ' . $extension, [Floor::SALES_QUEUE, Floor::SUPPORT_QUEUE], $isPersonControlled)],
        );
    }

    private function createTimings(
        float $unansweredRingProbability = 0.0,
        float $shortHangUpProbability = 0.0,
        float $meanSecondsBetweenRandomPauses = 1000.0,
    ): SimulationTimings {
        return new SimulationTimings(
            arrivalRatePeriodSeconds: 0.06,
            minimumPatienceSeconds: 0.03,
            maximumPatienceSeconds: 0.15,
            shortHangUpProbability: $shortHangUpProbability,
            shortHangUpSeconds: 0.005,
            minimumAnswerSeconds: 0.002,
            maximumAnswerSeconds: 0.005,
            ringTimeoutSeconds: 0.015,
            unansweredRingProbability: $unansweredRingProbability,
            minimumTalkSeconds: 0.02,
            maximumTalkSeconds: 0.06,
            wrapUpSeconds: 0.005,
            meanSecondsBetweenRandomPauses: $meanSecondsBetweenRandomPauses,
            minimumRandomPauseSeconds: 1.0,
            maximumRandomPauseSeconds: 2.0,
            inactivePauseSeconds: self::INACTIVE_PAUSE_SECONDS,
        );
    }

    /**
     * @param callable(): bool $isFinished
     */
    private function runLoopUntil(callable $isFinished, float $timeoutSeconds = 2.0): void
    {
        for ($waitedSeconds = 0.0; $waitedSeconds < $timeoutSeconds && !$isFinished(); $waitedSeconds += self::WAIT_STEP_SECONDS) {
            delay(self::WAIT_STEP_SECONDS);
        }

        self::assertTrue($isFinished(), sprintf('The awaited condition did not hold within %.3f s.', $timeoutSeconds));
    }

    private function runLoopFor(float $seconds): void
    {
        delay($seconds);
    }

    /**
     * @return list<AmiPacket>
     */
    private function filterPackets(string $eventName): array
    {
        return array_values(array_filter(
            $this->packets,
            static fn(AmiPacket $packet): bool => $packet->findEventName() === $eventName,
        ));
    }

    /**
     * @return list<AmiPacket>
     *
     * @phpstan-impure
     */
    private function filterResumes(): array
    {
        return array_values(array_filter(
            $this->filterPackets('QueueMemberPause'),
            static fn(AmiPacket $packet): bool => $packet->getField('Paused') === '0',
        ));
    }

    private function countWaitingCallers(string $queueName): int
    {
        $waitingCallerCount = 0;
        foreach ($this->packets as $packet) {
            if ($packet->findField('Queue') !== $queueName) {
                continue;
            }
            if ($packet->findEventName() === 'QueueCallerJoin') {
                ++$waitingCallerCount;
            }
            if ($packet->findEventName() === 'QueueCallerLeave') {
                --$waitingCallerCount;
            }
        }

        return $waitingCallerCount;
    }
}
