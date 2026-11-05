<?php

declare(strict_types=1);

namespace QueueWallboard\Simulator;

use QueueWallboard\Ami\AmiPacket;

/**
 * Events as Asterisk 22 sends them. Where its docs and apps/app_queue.c on branch 22 differ, this follows the code.
 */
final readonly class AmiEventFactory
{
    /** Asterisk's ChannelState numbers */
    private const int CHANNEL_STATE_RINGING = 5;
    private const int CHANNEL_STATE_UP = 6;
    private const string CHANNEL_UNKNOWN_CALLER_ID = '<unknown>';
    private const string QUEUE_ENTRY_UNKNOWN_CALLER_ID = 'unknown';

    public function createQueueCallerJoin(SimulatedCaller $caller, int $position, int $waitingCallerCount): AmiPacket
    {
        return $this->createCallerEvent('QueueCallerJoin', $caller, [
            'Position' => strval($position),
            'Count' => strval($waitingCallerCount),
        ]);
    }

    public function createQueueCallerLeave(SimulatedCaller $caller, int $position, int $waitingCallerCount): AmiPacket
    {
        return $this->createCallerEvent('QueueCallerLeave', $caller, [
            'Position' => strval($position),
            'Count' => strval($waitingCallerCount),
        ]);
    }

    public function createQueueCallerAbandon(SimulatedCaller $caller, int $position, int $holdSeconds): AmiPacket
    {
        return $this->createCallerEvent('QueueCallerAbandon', $caller, [
            'Position' => strval($position),
            'OriginalPosition' => strval($caller->originalPosition),
            'HoldTime' => strval($holdSeconds),
        ]);
    }

    public function createAgentCalled(SimulatedCaller $caller, SimulatedAgent $agent): AmiPacket
    {
        return $this->createAgentEvent('AgentCalled', $caller, $agent, self::CHANNEL_STATE_RINGING, []);
    }

    public function createAgentConnect(SimulatedCaller $caller, SimulatedAgent $agent, int $holdSeconds, int $ringSeconds): AmiPacket
    {
        return $this->createAgentEvent('AgentConnect', $caller, $agent, self::CHANNEL_STATE_UP, [
            'HoldTime' => strval($holdSeconds),
            'RingTime' => strval($ringSeconds),
        ]);
    }

    public function createAgentComplete(SimulatedCaller $caller, SimulatedAgent $agent, int $holdSeconds, int $talkSeconds): AmiPacket
    {
        return $this->createAgentEvent('AgentComplete', $caller, $agent, self::CHANNEL_STATE_UP, [
            'HoldTime' => strval($holdSeconds),
            'TalkTime' => strval($talkSeconds),
            'Reason' => 'caller',
        ]);
    }

    public function createAgentRingNoAnswer(SimulatedCaller $caller, SimulatedAgent $agent, int $ringMilliseconds): AmiPacket
    {
        return $this->createAgentEvent('AgentRingNoAnswer', $caller, $agent, self::CHANNEL_STATE_RINGING, [
            'RingTime' => strval($ringMilliseconds),
        ]);
    }

    public function createQueueMemberStatus(string $queueName, SimulatedAgent $agent): AmiPacket
    {
        return $this->createMemberEvent('QueueMemberStatus', $queueName, $agent);
    }

    public function createQueueMemberPause(string $queueName, SimulatedAgent $agent): AmiPacket
    {
        return $this->createMemberEvent('QueueMemberPause', $queueName, $agent);
    }

    public function createQueueParams(SimulatedQueue $queue, string $actionId): AmiPacket
    {
        return new AmiPacket([
            'Event' => 'QueueParams',
            'Queue' => $queue->name,
            'Max' => '0',
            'Strategy' => 'leastrecent',
            'Calls' => strval(count($queue->waitingCallers)),
            'Holdtime' => strval($queue->averageHoldSeconds),
            'TalkTime' => strval($queue->averageTalkSeconds),
            'Completed' => strval($queue->completedCallCount),
            'Abandoned' => strval($queue->abandonedCallCount),
            'ServiceLevel' => '0',
            'ServicelevelPerf' => '0.0',
            'ServicelevelPerf2' => '0.0',
            'Weight' => '0',
            'ActionID' => $actionId,
        ]);
    }

    public function createQueueMember(string $queueName, SimulatedAgent $agent, string $actionId): AmiPacket
    {
        return new AmiPacket([
            'Event' => 'QueueMember',
            'Queue' => $queueName,
            'Name' => $agent->profile->name,
            'Location' => $agent->profile->interface,
            ...$this->describeMemberState($agent, $queueName),
            'Wrapuptime' => '0',
            'ActionID' => $actionId,
        ]);
    }

    public function createQueueEntry(SimulatedCaller $caller, int $position, int $waitSeconds, string $actionId): AmiPacket
    {
        return new AmiPacket([
            'Event' => 'QueueEntry',
            'Queue' => $caller->queueName,
            'Position' => strval($position),
            'Channel' => $caller->channelName,
            'Uniqueid' => $caller->uniqueId,
            'CallerIDNum' => $caller->callerNumber,
            'CallerIDName' => self::QUEUE_ENTRY_UNKNOWN_CALLER_ID,
            'ConnectedLineNum' => self::QUEUE_ENTRY_UNKNOWN_CALLER_ID,
            'ConnectedLineName' => self::QUEUE_ENTRY_UNKNOWN_CALLER_ID,
            'Wait' => strval($waitSeconds),
            'Priority' => '0',
            'ActionID' => $actionId,
        ]);
    }

    public function createQueueStatusComplete(string $actionId, int $listItemCount): AmiPacket
    {
        return new AmiPacket([
            'Event' => 'QueueStatusComplete',
            'ActionID' => $actionId,
            'EventList' => 'Complete',
            'ListItems' => strval($listItemCount),
        ]);
    }

    /**
     * @param array<string, string> $eventFields
     */
    private function createCallerEvent(string $eventName, SimulatedCaller $caller, array $eventFields): AmiPacket
    {
        return new AmiPacket([
            ...$this->describeEventHeader($eventName),
            ...$this->describeCallerChannel($caller),
            'Queue' => $caller->queueName,
            ...$eventFields,
        ]);
    }

    /**
     * @param array<string, string> $eventFields
     */
    private function createAgentEvent(
        string $eventName,
        SimulatedCaller $caller,
        SimulatedAgent $agent,
        int $agentChannelState,
        array $eventFields,
    ): AmiPacket {
        return new AmiPacket([
            ...$this->describeEventHeader($eventName),
            ...$this->describeCallerChannel($caller),
            ...$this->describeChannel(
                'Dest',
                $agent->channelName,
                $agentChannelState,
                $agent->profile->extension,
                $agent->profile->name,
                $caller->callerNumber,
                'from-queue',
                $agent->profile->extension,
                $agent->channelUniqueId,
                $caller->uniqueId,
            ),
            'Queue' => $caller->queueName,
            'Interface' => $agent->profile->interface,
            'MemberName' => $agent->profile->name,
            ...$eventFields,
        ]);
    }

    private function createMemberEvent(string $eventName, string $queueName, SimulatedAgent $agent): AmiPacket
    {
        return new AmiPacket([
            ...$this->describeEventHeader($eventName),
            'Queue' => $queueName,
            'MemberName' => $agent->profile->name,
            'Interface' => $agent->profile->interface,
            ...$this->describeMemberState($agent, $queueName),
            'Ringinuse' => '0',
            'Wrapuptime' => '0',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function describeMemberState(SimulatedAgent $agent, string $queueName): array
    {
        return [
            'StateInterface' => $agent->profile->interface,
            'Membership' => 'static',
            'Penalty' => '0',
            'CallsTaken' => strval($agent->callsTaken),
            'LastCall' => strval($agent->lastCallEndedAt?->getTimestamp() ?? 0),
            'LastPause' => strval($agent->lastPausedAt?->getTimestamp() ?? 0),
            'LoginTime' => strval($agent->loggedInSince->getTimestamp()),
            'InCall' => $agent->connectedCaller?->queueName === $queueName ? '1' : '0',
            'Status' => strval($agent->getDeviceState()),
            'Paused' => $agent->isPaused ? '1' : '0',
            'PausedReason' => $agent->pausedReason,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function describeEventHeader(string $eventName): array
    {
        return ['Event' => $eventName, 'Privilege' => 'agent,all'];
    }

    /**
     * @return array<string, string>
     */
    private function describeCallerChannel(SimulatedCaller $caller): array
    {
        return $this->describeChannel(
            '',
            $caller->channelName,
            self::CHANNEL_STATE_UP,
            $caller->callerNumber,
            self::CHANNEL_UNKNOWN_CALLER_ID,
            self::CHANNEL_UNKNOWN_CALLER_ID,
            'from-trunk',
            $caller->queueName,
            $caller->uniqueId,
            $caller->uniqueId,
        );
    }

    /**
     * @return array<string, string>
     */
    private function describeChannel(
        string $fieldPrefix,
        string $channelName,
        int $channelState,
        string $callerIdNumber,
        string $callerIdName,
        string $connectedLineNumber,
        string $context,
        string $extension,
        string $uniqueId,
        string $linkedId,
    ): array {
        $channelFields = [
            'Channel' => $channelName,
            'ChannelState' => strval($channelState),
            'ChannelStateDesc' => $channelState === self::CHANNEL_STATE_UP ? 'Up' : 'Ringing',
            'CallerIDNum' => $callerIdNumber,
            'CallerIDName' => $callerIdName,
            'ConnectedLineNum' => $connectedLineNumber,
            'ConnectedLineName' => self::CHANNEL_UNKNOWN_CALLER_ID,
            'Language' => 'en',
            'AccountCode' => '',
            'Context' => $context,
            'Exten' => $extension,
            'Priority' => '1',
            'Uniqueid' => $uniqueId,
            'Linkedid' => $linkedId,
        ];

        $prefixedFields = [];
        foreach ($channelFields as $fieldName => $fieldValue) {
            $prefixedFields[$fieldPrefix . $fieldName] = $fieldValue;
        }

        return $prefixedFields;
    }
}
