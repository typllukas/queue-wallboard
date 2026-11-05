<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Daemon\Floor;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueueWallboard\Daemon\Floor\Agent;
use QueueWallboard\Daemon\Floor\ExtensionStatus;
use QueueWallboard\Daemon\Floor\FloorState;
use QueueWallboard\Daemon\Floor\PauseReason;
use QueueWallboard\Daemon\Floor\QueueSettings;
use QueueWallboard\Daemon\Floor\WaitingCall;
use Symfony\Component\Clock\MockClock;

#[CoversClass(FloorState::class)]
#[CoversClass(Agent::class)]
final class FloorStateTest extends TestCase
{
    private const string SALES_QUEUE = 'sales';
    private const string SUPPORT_QUEUE = 'support';

    private MockClock $clock;
    private FloorState $floor;

    #[Override]
    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-04 12:00:00 Europe/Prague');
        $this->floor = new FloorState(QueueSettings::createDemoSettings(), $this->clock);
        $this->floor->addAgent($this->createIdleAgent('201', [self::SALES_QUEUE]));
        $this->floor->addAgent($this->createIdleAgent('203', [self::SALES_QUEUE, self::SUPPORT_QUEUE]));
        $this->floor->addAgent($this->createIdleAgent('205', [self::SUPPORT_QUEUE]));
        $this->floor->takeChanges();
    }

    public function testAnAgentInBothQueuesCountsAsAvailableInEach(): void
    {
        self::assertSame(2, $this->floor->countAvailable(self::SALES_QUEUE));
        self::assertSame(2, $this->floor->countAvailable(self::SUPPORT_QUEUE));
    }

    public function testPausingAnAgentMarksTheAgentAndBothQueuesChanged(): void
    {
        $this->floor->updatePause('PJSIP/203', PauseReason::Lunch);

        $changes = $this->floor->takeChanges();

        self::assertSame(['203'], $changes->agentExtensions);
        self::assertEqualsCanonicalizing([self::SALES_QUEUE, self::SUPPORT_QUEUE], $changes->queueNames);
        self::assertSame(1, $this->floor->countAvailable(self::SALES_QUEUE));
        self::assertSame(1, $this->floor->countOnPause(self::SUPPORT_QUEUE, PauseReason::Lunch));
        self::assertSame(0, $this->floor->countOnPause(self::SUPPORT_QUEUE, PauseReason::Training));
    }

    public function testTakeChangesEmptiesTheChangeSet(): void
    {
        $this->floor->updatePause('PJSIP/201', PauseReason::Training);
        $this->floor->updateExchangeConnection(true);

        self::assertTrue($this->floor->takeChanges()->isExchangeConnectionChanged);

        $changes = $this->floor->takeChanges();

        self::assertSame([], $changes->agentExtensions);
        self::assertSame([], $changes->queueNames);
        self::assertFalse($changes->isExchangeConnectionChanged);
    }

    public function testCountersCountFromTheStartAndResetAtLocalMidnight(): void
    {
        $this->clock = new MockClock('2026-11-04 23:59:50 Europe/Prague');
        $floor = new FloorState(QueueSettings::createDemoSettings(), $this->clock);
        $floor->recordAnswer(self::SALES_QUEUE, 'PJSIP/201', 4);
        $floor->recordAbandon(self::SALES_QUEUE, 30);

        self::assertSame(2, $floor->findQueue(self::SALES_QUEUE)?->counters->countTotal());
        self::assertSame('2026-11-04T23:59:50+01:00', $floor->findQueue(self::SALES_QUEUE)->counters->countedSince->format(DATE_ATOM));

        $this->clock->sleep(20);
        $floor->resetCountersIfNewDay();

        self::assertSame(0, $floor->findQueue(self::SALES_QUEUE)?->counters->countTotal());
        self::assertSame('2026-11-05T00:00:00+01:00', $floor->findQueue(self::SALES_QUEUE)->counters->countedSince->format(DATE_ATOM));
    }

    public function testRingingCountsAsBusy(): void
    {
        $this->floor->updateExtensionStatus('PJSIP/201', ExtensionStatus::fromDeviceState(6));

        self::assertSame(1, $this->floor->countAvailable(self::SALES_QUEUE));
        self::assertSame(ExtensionStatus::Busy, $this->floor->findAgent('201')?->extensionStatus);
    }

    public function testAnOngoingCallEndsWithTheAgentsCallEnd(): void
    {
        $this->floor->recordAnswer(self::SALES_QUEUE, 'PJSIP/203', 3);

        self::assertSame(1, $this->floor->findQueue(self::SALES_QUEUE)?->countOngoingCalls());
        self::assertSame(0, $this->floor->findQueue(self::SUPPORT_QUEUE)?->countOngoingCalls());

        $this->floor->recordCallEnd('PJSIP/203');

        self::assertSame(0, $this->floor->findQueue(self::SALES_QUEUE)?->countOngoingCalls());
        self::assertEquals($this->clock->now(), $this->floor->findAgent('203')?->lastCallAt);
    }

    public function testANumericQueueNameStaysAString(): void
    {
        $floor = new FloorState([new QueueSettings(name: '500', title: 'Hotline', thresholdCalls: 5, thresholdUsers: 1, thresholdTime: 60)], $this->clock);
        $floor->addAgent($this->createIdleAgent('203', ['500']));
        $floor->recordAnswer('500', 'PJSIP/203', 3);
        $floor->takeChanges();

        $floor->recordCallEnd('PJSIP/203');

        self::assertSame(0, $floor->findQueue('500')?->countOngoingCalls());
        self::assertSame(['500'], $floor->takeChanges()->queueNames);
    }

    public function testACallEndClearsTheAgentsOngoingCallInEveryQueue(): void
    {
        $this->floor->recordAnswer(self::SALES_QUEUE, 'PJSIP/203', 3);
        $this->floor->recordAnswer(self::SUPPORT_QUEUE, 'PJSIP/203', 3);

        $this->floor->recordCallEnd('PJSIP/203');

        self::assertSame(0, $this->floor->findQueue(self::SALES_QUEUE)?->countOngoingCalls());
        self::assertSame(0, $this->floor->findQueue(self::SUPPORT_QUEUE)?->countOngoingCalls());
    }

    public function testACallEndOnAnInterfaceWithNoAgentMarksChangedOnlyTheQueueThatHeldIt(): void
    {
        $this->floor->recordAnswer(self::SALES_QUEUE, 'PJSIP/299', 3);
        $this->floor->takeChanges();

        $this->floor->recordCallEnd('PJSIP/299');

        self::assertSame([self::SALES_QUEUE], $this->floor->takeChanges()->queueNames);
    }

    public function testReplacingTheLiveStateForgetsChangesOfRemovedAgents(): void
    {
        $this->floor->updatePause('PJSIP/205', PauseReason::Lunch);

        $this->floor->replaceLiveState([$this->createIdleAgent('201', [self::SALES_QUEUE])], [], []);

        self::assertSame(['201'], $this->floor->takeChanges()->agentExtensions);
        self::assertNull($this->floor->findAgent('205'));
    }

    public function testWaitingCallsAreListedLongestFirst(): void
    {
        $this->floor->addWaitingCall(self::SALES_QUEUE, 'late', $this->clock->now());
        $this->floor->addWaitingCall(self::SALES_QUEUE, 'early', $this->clock->now()->modify('-30 seconds'));

        $waitingCalls = $this->floor->findQueue(self::SALES_QUEUE)?->listWaitingCallsLongestFirst() ?? [];

        self::assertSame(['early', 'late'], array_map(static fn(WaitingCall $waitingCall): string => $waitingCall->id, $waitingCalls));

        $this->floor->removeWaitingCall(self::SALES_QUEUE, 'early');

        self::assertCount(1, $this->floor->findQueue(self::SALES_QUEUE)?->listWaitingCallsLongestFirst() ?? []);
    }

    public function testTheStateClockRestartsOnlyWhenTheStateChanges(): void
    {
        $startedAt = $this->clock->now();
        $this->clock->sleep(10);
        $this->floor->updateExtensionStatus('PJSIP/201', ExtensionStatus::Idle);

        self::assertEquals($startedAt, $this->floor->findAgent('201')?->stateSince);

        $this->floor->updatePause('PJSIP/201', PauseReason::Lunch);

        self::assertEquals($this->clock->now(), $this->floor->findAgent('201')?->stateSince);
        self::assertEquals($this->clock->now(), $this->floor->findAgent('201')?->pausedSince);
    }

    public function testARepeatedIdenticalPauseKeepsTheFirstPausedSince(): void
    {
        $this->floor->updatePause('PJSIP/203', PauseReason::Lunch);
        $pausedAt = $this->clock->now();
        $this->clock->sleep(5);
        $this->floor->updatePause('PJSIP/203', PauseReason::Lunch);

        self::assertEquals($pausedAt, $this->floor->findAgent('203')?->pausedSince);
        self::assertEquals($pausedAt, $this->floor->findAgent('203')?->stateSince);
    }

    public function testAChangedPauseReasonRestartsPausedSince(): void
    {
        $this->floor->updatePause('PJSIP/203', PauseReason::Lunch);
        $this->clock->sleep(5);
        $this->floor->updatePause('PJSIP/203', PauseReason::Training);

        self::assertEquals($this->clock->now(), $this->floor->findAgent('203')?->pausedSince);
    }

    /**
     * @param list<string> $queueNames
     */
    private function createIdleAgent(string $extension, array $queueNames): Agent
    {
        return new Agent(
            extension: $extension,
            title: 'Agent ' . $extension,
            queueNames: $queueNames,
            loggedInSince: $this->clock->now(),
            extensionStatus: ExtensionStatus::Idle,
            pauseReason: null,
            pausedSince: null,
            lastCallAt: null,
            stateSince: $this->clock->now(),
        );
    }
}
