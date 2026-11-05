<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Simulator;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueueWallboard\Simulator\AgentProfile;
use QueueWallboard\Simulator\AmiEventFactory;
use QueueWallboard\Simulator\SimulatedAgent;
use QueueWallboard\Simulator\SimulatedCaller;
use QueueWallboard\Simulator\SimulationScheduler;
use React\EventLoop\Loop;

#[CoversClass(AmiEventFactory::class)]
final class AmiEventFactoryTest extends TestCase
{
    private const string CALLER_UNIQUE_ID = '1762160400.1';
    private const string AGENT_UNIQUE_ID = '1762160403.2';
    private const string AGENT_CHANNEL = 'PJSIP/203-00000002';

    public function testAgentEventsCarryTheAgentChannelWithTheDestPrefix(): void
    {
        $agentConnect = new AmiEventFactory()->createAgentConnect($this->createCaller(), $this->createRingingAgent(), 3, 3);

        self::assertSame('AgentConnect', $agentConnect->findEventName());
        self::assertSame(self::CALLER_UNIQUE_ID, $agentConnect->getField('Uniqueid'));
        self::assertSame('PJSIP/trunk-00000001', $agentConnect->getField('Channel'));
        self::assertSame(self::AGENT_UNIQUE_ID, $agentConnect->getField('DestUniqueid'));
        self::assertSame(self::AGENT_CHANNEL, $agentConnect->getField('DestChannel'));
        self::assertSame('PJSIP/203', $agentConnect->getField('Interface'));
        self::assertSame('Lucie Dvořáková', $agentConnect->getField('MemberName'));
    }

    public function testQueueMemberInTheStatusReplyUsesNameAndLocation(): void
    {
        $queueMember = new AmiEventFactory()->createQueueMember('support', $this->createRingingAgent(), 'status-1');

        self::assertSame('QueueMember', $queueMember->findEventName());
        self::assertSame('Lucie Dvořáková', $queueMember->getField('Name'));
        self::assertSame('PJSIP/203', $queueMember->getField('Location'));
        self::assertNull($queueMember->findField('MemberName'));
        self::assertNull($queueMember->findField('Interface'));
        self::assertSame('status-1', $queueMember->findActionId());
    }

    public function testQueueMemberIsInCallOnlyInTheQueueOfItsCall(): void
    {
        $agent = $this->createRingingAgent();
        $agent->connect($this->createCaller());
        $eventFactory = new AmiEventFactory();

        self::assertSame('1', $eventFactory->createQueueMember('support', $agent, 'status-1')->getField('InCall'));
        self::assertSame('0', $eventFactory->createQueueMember('sales', $agent, 'status-1')->getField('InCall'));
        self::assertSame('0', $eventFactory->createQueueMember('support', $this->createRingingAgent(), 'status-1')->getField('InCall'));
    }

    public function testRingTimeIsSecondsOnConnectAndMillisecondsOnRingNoAnswer(): void
    {
        $eventFactory = new AmiEventFactory();

        $agentConnect = $eventFactory->createAgentConnect($this->createCaller(), $this->createRingingAgent(), 10, 3);
        $agentRingNoAnswer = $eventFactory->createAgentRingNoAnswer($this->createCaller(), $this->createRingingAgent(), 15000);

        self::assertSame('3', $agentConnect->getField('RingTime'));
        self::assertSame('15000', $agentRingNoAnswer->getField('RingTime'));
    }

    public function testQueueMemberPauseCarriesThePausedReason(): void
    {
        $agent = $this->createRingingAgent();
        $agent->pause('Lunch', new DateTimeImmutable('2026-11-03 10:05:00'));

        $queueMemberPause = new AmiEventFactory()->createQueueMemberPause('support', $agent);

        self::assertSame('QueueMemberPause', $queueMemberPause->findEventName());
        self::assertSame('PJSIP/203', $queueMemberPause->getField('Interface'));
        self::assertSame('1', $queueMemberPause->getField('Paused'));
        self::assertSame('Lunch', $queueMemberPause->getField('PausedReason'));
    }

    private function createCaller(): SimulatedCaller
    {
        return new SimulatedCaller(
            self::CALLER_UNIQUE_ID,
            'PJSIP/trunk-00000001',
            '+420601234567',
            'support',
            new DateTimeImmutable('2026-11-03 10:00:00'),
            1,
            new SimulationScheduler(Loop::get()),
        );
    }

    private function createRingingAgent(): SimulatedAgent
    {
        $agent = new SimulatedAgent(
            new AgentProfile('203', 'Lucie Dvořáková', ['sales', 'support'], true),
            new DateTimeImmutable('2026-11-03 08:00:00'),
            new SimulationScheduler(Loop::get()),
        );
        $agent->startRinging($this->createCaller(), self::AGENT_UNIQUE_ID, self::AGENT_CHANNEL);

        return $agent;
    }
}
