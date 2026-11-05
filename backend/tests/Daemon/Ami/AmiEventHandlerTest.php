<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Daemon\Ami;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueueWallboard\Ami\AmiPacket;
use QueueWallboard\Daemon\Ami\AmiEventHandler;
use QueueWallboard\Daemon\Floor\Agent;
use QueueWallboard\Daemon\Floor\ExtensionStatus;
use QueueWallboard\Daemon\Floor\FloorState;
use QueueWallboard\Daemon\Floor\PauseReason;
use QueueWallboard\Daemon\Floor\QueueSettings;
use Symfony\Component\Clock\MockClock;

#[CoversClass(AmiEventHandler::class)]
final class AmiEventHandlerTest extends TestCase
{
    private const string CALLER_UNIQUE_ID = '1762160000.1';
    private const string AGENT_UNIQUE_ID = '1762160000.2';

    private MockClock $clock;
    private FloorState $floor;
    private AmiEventHandler $eventHandler;

    #[Override]
    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-03 10:00:00 Europe/Prague');
        $this->floor = new FloorState(QueueSettings::createDemoSettings(), $this->clock);
        $this->floor->addAgent($this->createIdleAgent('203', ['sales', 'support']));
        $this->eventHandler = new AmiEventHandler($this->floor, $this->clock);
    }

    public function testAnswerAndCallEndFindTheAgentByInterface(): void
    {
        $supportQueue = $this->floor->findQueue('support');
        self::assertNotNull($supportQueue);

        $this->eventHandler->handleEvent($this->createCallerEvent('QueueCallerJoin', ['Position' => '1', 'Count' => '1']));

        self::assertCount(1, $supportQueue->listWaitingCallsLongestFirst());

        $this->eventHandler->handleEvent($this->createCallerEvent('QueueCallerLeave', ['Position' => '1', 'Count' => '0']));
        $this->eventHandler->handleEvent($this->createAgentEvent('AgentConnect', ['HoldTime' => '3', 'RingTime' => '3']));

        self::assertSame([], $supportQueue->listWaitingCallsLongestFirst());
        self::assertSame(1, $supportQueue->countOngoingCalls());
        self::assertSame(1, $supportQueue->counters->countAnswered());

        $this->eventHandler->handleEvent($this->createAgentEvent('AgentComplete', [
            'HoldTime' => '3',
            'TalkTime' => '60',
            'Reason' => 'caller',
        ]));

        self::assertSame(0, $supportQueue->countOngoingCalls());
        self::assertEquals($this->clock->now(), $this->floor->findAgent('203')?->lastCallAt);
    }

    public function testACallerLeavingTheQueueRemovesTheWaitingCall(): void
    {
        $this->eventHandler->handleEvent($this->createCallerEvent('QueueCallerJoin', ['Position' => '1', 'Count' => '1']));
        $this->eventHandler->handleEvent($this->createCallerEvent('QueueCallerLeave', ['Position' => '1', 'Count' => '0']));

        self::assertSame([], $this->floor->findQueue('support')?->listWaitingCallsLongestFirst());
    }

    public function testAutoPauseBecomesTheInactivePause(): void
    {
        $this->eventHandler->handleEvent($this->createMemberPauseEvent('Auto-Pause'));

        self::assertSame(PauseReason::Inactive, $this->floor->findAgent('203')?->pauseReason);
    }

    public function testUnpauseClearsThePause(): void
    {
        $this->eventHandler->handleEvent($this->createMemberPauseEvent('Lunch'));
        $this->eventHandler->handleEvent($this->createMemberPauseEvent(null));

        self::assertNull($this->floor->findAgent('203')?->pauseReason);
    }

    public function testAMemberStatusChangesTheExtensionStatus(): void
    {
        $this->eventHandler->handleEvent(new AmiPacket([
            'Event' => 'QueueMemberStatus',
            'Queue' => 'support',
            'Interface' => 'PJSIP/203',
            'Status' => '2',
            'Paused' => '0',
            'PausedReason' => '',
        ]));

        self::assertSame(ExtensionStatus::Busy, $this->floor->findAgent('203')?->extensionStatus);
    }

    public function testAnAbandonCountsAsUnanswered(): void
    {
        $this->eventHandler->handleEvent($this->createCallerEvent('QueueCallerAbandon', [
            'Position' => '1',
            'OriginalPosition' => '1',
            'HoldTime' => '12',
        ]));

        self::assertSame(1, $this->floor->findQueue('support')?->counters->countUnanswered());
    }

    public function testRingNoAnswerDoesNotPauseTheAgent(): void
    {
        $this->eventHandler->handleEvent($this->createAgentEvent('AgentCalled', []));
        $this->eventHandler->handleEvent($this->createAgentEvent('AgentRingNoAnswer', ['RingTime' => '15000']));

        self::assertNull($this->floor->findAgent('203')?->pauseReason);
        self::assertTrue($this->floor->findAgent('203')?->isAvailable());
    }

    public function testAnUnknownEventIsIgnored(): void
    {
        $this->floor->takeChanges();

        $this->eventHandler->handleEvent(new AmiPacket(['Event' => 'PeerStatus', 'Peer' => 'PJSIP/203']));

        self::assertSame([], $this->floor->takeChanges()->agentExtensions);
    }

    /**
     * @param array<string, string> $eventFields
     */
    private function createCallerEvent(string $eventName, array $eventFields): AmiPacket
    {
        return new AmiPacket([
            'Event' => $eventName,
            'Privilege' => 'agent,all',
            'Channel' => 'PJSIP/trunk-00000001',
            'ChannelState' => '6',
            'ChannelStateDesc' => 'Up',
            'CallerIDNum' => '+420601234567',
            'CallerIDName' => '<unknown>',
            'ConnectedLineNum' => '<unknown>',
            'ConnectedLineName' => '<unknown>',
            'Language' => 'en',
            'AccountCode' => '',
            'Context' => 'from-trunk',
            'Exten' => 'support',
            'Priority' => '1',
            'Uniqueid' => self::CALLER_UNIQUE_ID,
            'Linkedid' => self::CALLER_UNIQUE_ID,
            'Queue' => 'support',
            ...$eventFields,
        ]);
    }

    /**
     * @param array<string, string> $eventFields
     */
    private function createAgentEvent(string $eventName, array $eventFields): AmiPacket
    {
        return new AmiPacket([
            'Event' => $eventName,
            'Privilege' => 'agent,all',
            'Channel' => 'PJSIP/trunk-00000001',
            'ChannelState' => '6',
            'ChannelStateDesc' => 'Up',
            'CallerIDNum' => '+420601234567',
            'CallerIDName' => '<unknown>',
            'ConnectedLineNum' => '<unknown>',
            'ConnectedLineName' => '<unknown>',
            'Language' => 'en',
            'AccountCode' => '',
            'Context' => 'from-trunk',
            'Exten' => 'support',
            'Priority' => '1',
            'Uniqueid' => self::CALLER_UNIQUE_ID,
            'Linkedid' => self::CALLER_UNIQUE_ID,
            'DestChannel' => 'PJSIP/203-00000002',
            'DestChannelState' => '6',
            'DestChannelStateDesc' => 'Up',
            'DestCallerIDNum' => '203',
            'DestCallerIDName' => 'Lucie Dvořáková',
            'DestConnectedLineNum' => '+420601234567',
            'DestConnectedLineName' => '<unknown>',
            'DestLanguage' => 'en',
            'DestAccountCode' => '',
            'DestContext' => 'from-queue',
            'DestExten' => '203',
            'DestPriority' => '1',
            'DestUniqueid' => self::AGENT_UNIQUE_ID,
            'DestLinkedid' => self::CALLER_UNIQUE_ID,
            'Queue' => 'support',
            'Interface' => 'PJSIP/203',
            'MemberName' => 'Lucie Dvořáková',
            ...$eventFields,
        ]);
    }

    private function createMemberPauseEvent(?string $pausedReason): AmiPacket
    {
        return new AmiPacket([
            'Event' => 'QueueMemberPause',
            'Privilege' => 'agent,all',
            'Queue' => 'support',
            'MemberName' => 'Lucie Dvořáková',
            'Interface' => 'PJSIP/203',
            'StateInterface' => 'PJSIP/203',
            'Membership' => 'static',
            'Penalty' => '0',
            'CallsTaken' => '0',
            'LastCall' => '0',
            'LastPause' => '0',
            'LoginTime' => '0',
            'InCall' => '0',
            'Status' => '1',
            'Paused' => $pausedReason === null ? '0' : '1',
            'PausedReason' => $pausedReason ?? '',
            'Ringinuse' => '0',
            'Wrapuptime' => '0',
        ]);
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
