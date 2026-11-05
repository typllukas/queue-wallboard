<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Daemon\Ami;

use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueueWallboard\Ami\AmiPacket;
use QueueWallboard\Daemon\Ami\AmiEventHandler;
use QueueWallboard\Daemon\Ami\QueueStatusResync;
use QueueWallboard\Daemon\Floor\ExtensionStatus;
use QueueWallboard\Daemon\Floor\FloorState;
use QueueWallboard\Daemon\Floor\PauseReason;
use QueueWallboard\Daemon\Floor\QueueSettings;
use Symfony\Component\Clock\MockClock;

#[CoversClass(QueueStatusResync::class)]
final class QueueStatusResyncTest extends TestCase
{
    private const string ACTION_ID = '7';
    private const string DEVICE_NOT_IN_USE = '1';
    private const string DEVICE_IN_USE = '2';
    private const string DEVICE_RINGING = '6';
    private const string DEVICE_ON_HOLD = '8';
    private const string LOGIN_TIME = '2026-11-03 08:00:00';
    private const string LAST_CALL_TIME = '2026-11-03 09:40:00';
    private const string LAST_PAUSE_TIME = '2026-11-03 09:50:00';

    private MockClock $clock;
    private FloorState $floor;
    private RecordingLogger $logger;
    private QueueStatusResync $resync;

    #[Override]
    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-03 10:00:00 Europe/Prague');
        $this->floor = new FloorState(QueueSettings::createDemoSettings(), $this->clock);
        $this->logger = new RecordingLogger();
        $this->resync = new QueueStatusResync(
            $this->floor,
            new AmiEventHandler($this->floor, $this->clock),
            $this->clock,
            $this->logger,
        );
    }

    public function testRebuildsAgentsAndWaitingCallsFromTheStatusReply(): void
    {
        $this->resync->begin(self::ACTION_ID);
        $this->acceptAll([
            $this->createQueueParamsItem('sales'),
            $this->createQueueMemberItem('sales', '201', 'Jana Nováková', self::DEVICE_NOT_IN_USE),
            $this->createQueueMemberItem('sales', '203', 'Lucie Dvořáková', self::DEVICE_IN_USE, isInCall: true),
            $this->createQueueEntryItem('sales', '1762160000.1', '30'),
            $this->createQueueParamsItem('support'),
            $this->createQueueMemberItem('support', '203', 'Lucie Dvořáková', self::DEVICE_IN_USE),
            $this->createQueueMemberItem('support', '205', 'Eva Procházková', self::DEVICE_NOT_IN_USE, 'Lunch'),
            $this->createQueueStatusComplete(),
        ]);

        $idleAgent = $this->floor->findAgent('201');
        self::assertNotNull($idleAgent);
        self::assertSame('Jana Nováková', $idleAgent->title);
        self::assertSame(['sales'], $idleAgent->queueNames);
        self::assertSame(ExtensionStatus::Idle, $idleAgent->extensionStatus);
        self::assertEquals($this->createTime(self::LOGIN_TIME), $idleAgent->loggedInSince);
        self::assertEquals($this->createTime(self::LAST_CALL_TIME), $idleAgent->lastCallAt);

        $agentInBothQueues = $this->floor->findAgent('203');
        self::assertNotNull($agentInBothQueues);
        self::assertSame(['sales', 'support'], $agentInBothQueues->queueNames);
        self::assertSame(ExtensionStatus::Busy, $agentInBothQueues->extensionStatus);

        $pausedAgent = $this->floor->findAgent('205');
        self::assertNotNull($pausedAgent);
        self::assertSame(PauseReason::Lunch, $pausedAgent->pauseReason);
        self::assertEquals($this->createTime(self::LAST_PAUSE_TIME), $pausedAgent->pausedSince);
        self::assertEquals($this->createTime(self::LAST_PAUSE_TIME), $pausedAgent->stateSince);

        $salesWaitingCalls = $this->floor->findQueue('sales')?->listWaitingCallsLongestFirst() ?? [];
        self::assertCount(1, $salesWaitingCalls);
        self::assertSame('1762160000.1', $salesWaitingCalls[0]->id);
        self::assertEquals($this->clock->now()->modify('-30 seconds'), $salesWaitingCalls[0]->waitingSince);
        self::assertSame([], $this->floor->findQueue('support')?->listWaitingCallsLongestFirst());

        self::assertSame(1, $this->floor->findQueue('sales')?->countOngoingCalls());
        self::assertSame(0, $this->floor->findQueue('support')?->countOngoingCalls());
        self::assertCount(3, $this->floor->listAgents());
    }

    public function testAMemberWithoutACallOrAPauseIsInItsStateSinceLogin(): void
    {
        $this->resync->begin(self::ACTION_ID);
        $this->acceptAll([
            $this->createQueueMemberItem('sales', '202', 'Petr Svoboda', self::DEVICE_NOT_IN_USE, hasTakenACall: false),
            $this->createQueueStatusComplete(),
        ]);

        $agentWithoutACall = $this->floor->findAgent('202');
        self::assertNotNull($agentWithoutACall);
        self::assertNull($agentWithoutACall->lastCallAt);
        self::assertEquals($this->createTime(self::LOGIN_TIME), $agentWithoutACall->stateSince);
    }

    public function testAMemberOnHoldCountsAsAnOngoingCall(): void
    {
        $this->resync->begin(self::ACTION_ID);
        $this->acceptAll([
            $this->createQueueMemberItem('support', '206', 'Martin Kučera', self::DEVICE_ON_HOLD, isInCall: true),
            $this->createQueueStatusComplete(),
        ]);

        self::assertSame(1, $this->floor->findQueue('support')?->countOngoingCalls());
    }

    public function testARingingMemberIsBusyButHasNoOngoingCall(): void
    {
        $this->resync->begin(self::ACTION_ID);
        $this->acceptAll([
            $this->createQueueMemberItem('support', '206', 'Martin Kučera', self::DEVICE_RINGING),
            $this->createQueueStatusComplete(),
        ]);

        self::assertSame(ExtensionStatus::Busy, $this->floor->findAgent('206')?->extensionStatus);
        self::assertSame(0, $this->floor->findQueue('support')?->countOngoingCalls());
    }

    public function testAnEventArrivingMidListIsReplayedAfterTheList(): void
    {
        $this->resync->begin(self::ACTION_ID);
        $this->acceptAll([$this->createQueueMemberItem('sales', '203', 'Lucie Dvořáková', self::DEVICE_NOT_IN_USE)]);

        self::assertTrue($this->resync->acceptPacket($this->createMemberPauseEvent('sales', '203', 'Lunch')));
        self::assertNull($this->floor->findAgent('203'));

        $this->acceptAll([
            $this->createQueueMemberItem('support', '203', 'Lucie Dvořáková', self::DEVICE_NOT_IN_USE),
            $this->createQueueStatusComplete(),
        ]);

        self::assertSame(PauseReason::Lunch, $this->floor->findAgent('203')?->pauseReason);
    }

    public function testCountersSurviveAResync(): void
    {
        $this->floor->recordAnswer('sales', 'PJSIP/201', 4);
        $this->floor->recordAbandon('sales', 30);

        $this->resync->begin(self::ACTION_ID);
        $this->acceptAll([
            $this->createQueueMemberItem('sales', '201', 'Jana Nováková', self::DEVICE_NOT_IN_USE),
            $this->createQueueStatusComplete(),
        ]);

        $salesCounters = $this->floor->findQueue('sales')?->counters;
        self::assertNotNull($salesCounters);
        self::assertSame(1, $salesCounters->countAnswered());
        self::assertSame(1, $salesCounters->countUnanswered());
        self::assertSame(0, $this->floor->findQueue('sales')?->countOngoingCalls());
    }

    public function testExchangeIsConnectedOnlyAfterTheListCompletes(): void
    {
        $this->resync->begin(self::ACTION_ID);
        $this->acceptAll([$this->createQueueMemberItem('sales', '201', 'Jana Nováková', self::DEVICE_NOT_IN_USE)]);

        self::assertFalse($this->floor->isExchangeConnected());
        self::assertTrue($this->resync->isListing());

        $this->acceptAll([$this->createQueueStatusComplete()]);

        self::assertTrue($this->floor->isExchangeConnected());
        self::assertFalse($this->resync->isListing());
    }

    public function testOnlyEventsAreConsumedAndOnlyWhileTheListStreams(): void
    {
        $statusResponse = new AmiPacket(['Response' => 'Success', 'ActionID' => self::ACTION_ID, 'EventList' => 'start']);

        self::assertFalse($this->resync->acceptPacket($this->createMemberPauseEvent('sales', '201', 'Lunch')));

        $this->resync->begin(self::ACTION_ID);

        self::assertFalse($this->resync->acceptPacket($statusResponse));
        self::assertTrue($this->resync->acceptPacket($this->createMemberPauseEvent('sales', '201', 'Lunch')));

        $this->resync->acceptPacket($this->createQueueStatusComplete());

        self::assertFalse($this->resync->acceptPacket($this->createMemberPauseEvent('sales', '201', 'Lunch')));
    }

    public function testABadBufferedEventIsLoggedAndTheReplayContinues(): void
    {
        $this->resync->begin(self::ACTION_ID);
        $this->acceptAll([
            $this->createQueueMemberItem('sales', '201', 'Jana Nováková', self::DEVICE_NOT_IN_USE),
            new AmiPacket(['Event' => 'QueueMemberPause', 'Queue' => 'sales', 'Paused' => '1']),
            $this->createMemberPauseEvent('sales', '201', 'Training'),
            $this->createQueueStatusComplete(),
        ]);

        self::assertSame(PauseReason::Training, $this->floor->findAgent('201')?->pauseReason);
        self::assertTrue($this->floor->isExchangeConnected());
        self::assertCount(1, $this->logger->findRecords('Replaying an AMI event failed ({eventName}): {error}'));
    }

    public function testAMemberPausedWithoutAReasonIsOnTheOtherPause(): void
    {
        $this->resync->begin(self::ACTION_ID);
        $this->acceptAll([
            $this->createQueueMemberItem('sales', '201', 'Jana Nováková', self::DEVICE_NOT_IN_USE, ''),
            $this->createQueueStatusComplete(),
        ]);

        self::assertSame(PauseReason::Other, $this->floor->findAgent('201')?->pauseReason);
    }

    public function testABadListItemSkipsOnlyThatAgent(): void
    {
        $this->resync->begin(self::ACTION_ID);
        $this->acceptAll([
            new AmiPacket(['Event' => 'QueueMember', 'Queue' => 'sales', 'Location' => 'PJSIP/201', 'Paused' => '0', 'ActionID' => self::ACTION_ID]),
            $this->createQueueMemberItem('sales', '202', 'Petr Svoboda', self::DEVICE_NOT_IN_USE),
            $this->createQueueStatusComplete(),
        ]);

        self::assertNull($this->floor->findAgent('201'));
        self::assertNotNull($this->floor->findAgent('202'));
        self::assertCount(1, $this->logger->findRecords('Skipping QueueStatus member {location}: {error}'));
    }

    public function testAbortMarksTheExchangeDisconnectedAndForgetsTheList(): void
    {
        $this->resync->begin(self::ACTION_ID);
        $this->acceptAll([$this->createQueueStatusComplete()]);
        $this->resync->begin('8');
        $this->resync->abort();

        self::assertFalse($this->floor->isExchangeConnected());
        self::assertFalse($this->resync->isListing());
    }

    /**
     * @param list<AmiPacket> $packets
     */
    private function acceptAll(array $packets): void
    {
        foreach ($packets as $packet) {
            self::assertTrue($this->resync->acceptPacket($packet));
        }
    }

    private function createQueueParamsItem(string $queueName): AmiPacket
    {
        return new AmiPacket([
            'Event' => 'QueueParams',
            'Queue' => $queueName,
            'Max' => '0',
            'Strategy' => 'leastrecent',
            'Calls' => '0',
            'Holdtime' => '0',
            'TalkTime' => '0',
            'Completed' => '0',
            'Abandoned' => '0',
            'ServiceLevel' => '0',
            'ServicelevelPerf' => '0.0',
            'ServicelevelPerf2' => '0.0',
            'Weight' => '0',
            'ActionID' => self::ACTION_ID,
        ]);
    }

    private function createQueueMemberItem(
        string $queueName,
        string $extension,
        string $memberName,
        string $deviceState,
        ?string $pausedReason = null,
        bool $hasTakenACall = true,
        bool $isInCall = false,
    ): AmiPacket {
        return new AmiPacket([
            'Event' => 'QueueMember',
            'Queue' => $queueName,
            'Name' => $memberName,
            'Location' => 'PJSIP/' . $extension,
            'StateInterface' => 'PJSIP/' . $extension,
            'Membership' => 'static',
            'Penalty' => '0',
            'CallsTaken' => '3',
            'LastCall' => $hasTakenACall ? strval($this->createTime(self::LAST_CALL_TIME)->getTimestamp()) : '0',
            'LastPause' => $pausedReason === null ? '0' : strval($this->createTime(self::LAST_PAUSE_TIME)->getTimestamp()),
            'LoginTime' => strval($this->createTime(self::LOGIN_TIME)->getTimestamp()),
            'InCall' => $isInCall ? '1' : '0',
            'Status' => $deviceState,
            'Paused' => $pausedReason === null ? '0' : '1',
            'PausedReason' => $pausedReason ?? '',
            'Wrapuptime' => '0',
            'ActionID' => self::ACTION_ID,
        ]);
    }

    private function createQueueEntryItem(string $queueName, string $uniqueId, string $waitSeconds): AmiPacket
    {
        return new AmiPacket([
            'Event' => 'QueueEntry',
            'Queue' => $queueName,
            'Position' => '1',
            'Channel' => 'PJSIP/trunk-00000001',
            'Uniqueid' => $uniqueId,
            'CallerIDNum' => '+420601234567',
            'CallerIDName' => 'unknown',
            'ConnectedLineNum' => 'unknown',
            'ConnectedLineName' => 'unknown',
            'Wait' => $waitSeconds,
            'Priority' => '0',
            'ActionID' => self::ACTION_ID,
        ]);
    }

    private function createQueueStatusComplete(): AmiPacket
    {
        return new AmiPacket([
            'Event' => 'QueueStatusComplete',
            'ActionID' => self::ACTION_ID,
            'EventList' => 'Complete',
            'ListItems' => '0',
        ]);
    }

    private function createMemberPauseEvent(string $queueName, string $extension, string $pausedReason): AmiPacket
    {
        return new AmiPacket([
            'Event' => 'QueueMemberPause',
            'Privilege' => 'agent,all',
            'Queue' => $queueName,
            'MemberName' => 'Agent ' . $extension,
            'Interface' => 'PJSIP/' . $extension,
            'StateInterface' => 'PJSIP/' . $extension,
            'Membership' => 'static',
            'Penalty' => '0',
            'CallsTaken' => '0',
            'LastCall' => '0',
            'LastPause' => strval($this->clock->now()->getTimestamp()),
            'LoginTime' => '0',
            'InCall' => '0',
            'Status' => '1',
            'Paused' => '1',
            'PausedReason' => $pausedReason,
            'Ringinuse' => '0',
            'Wrapuptime' => '0',
        ]);
    }

    private function createTime(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time . ' Europe/Prague');
    }
}
