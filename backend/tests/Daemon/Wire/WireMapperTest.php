<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Daemon\Wire;

use DateTimeImmutable;
use DateTimeZone;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueueWallboard\Daemon\Floor\Agent;
use QueueWallboard\Daemon\Floor\ExtensionStatus;
use QueueWallboard\Daemon\Floor\FloorChanges;
use QueueWallboard\Daemon\Floor\FloorState;
use QueueWallboard\Daemon\Floor\PauseReason;
use QueueWallboard\Daemon\Floor\QueueSettings;
use QueueWallboard\Daemon\Wire\WireMapper;
use Symfony\Component\Clock\MockClock;

#[CoversClass(WireMapper::class)]
final class WireMapperTest extends TestCase
{
    private const string SALES_QUEUE = 'sales';
    private const string SUPPORT_QUEUE = 'support';
    private WireMapper $mapper;

    #[Override]
    protected function setUp(): void
    {
        $clock = new MockClock('2026-11-03 10:00:00 Europe/Prague');
        $floor = new FloorState(QueueSettings::createDemoSettings(), $clock);
        $floor->addAgent(new Agent(
            extension: '201',
            title: 'Jana Nováková',
            queueNames: [self::SALES_QUEUE],
            loggedInSince: $this->createTime('08:00:00'),
            extensionStatus: ExtensionStatus::Idle,
            pauseReason: null,
            pausedSince: null,
            lastCallAt: $this->createTime('09:30:00'),
            stateSince: $this->createTime('09:30:00'),
        ));
        $floor->addAgent(new Agent(
            extension: '203',
            title: 'Petr Svoboda',
            queueNames: [self::SALES_QUEUE, self::SUPPORT_QUEUE],
            loggedInSince: $this->createTime('08:00:00'),
            extensionStatus: ExtensionStatus::Idle,
            pauseReason: PauseReason::Lunch,
            pausedSince: $this->createTime('09:50:00'),
            lastCallAt: $this->createTime('09:45:00'),
            stateSince: $this->createTime('09:50:00'),
        ));
        $floor->addAgent(new Agent(
            extension: '205',
            title: 'Marie Dvořáková',
            queueNames: [self::SUPPORT_QUEUE],
            loggedInSince: $this->createTime('08:15:00'),
            extensionStatus: ExtensionStatus::Busy,
            pauseReason: null,
            pausedSince: null,
            lastCallAt: null,
            stateSince: $this->createTime('09:58:00'),
        ));
        $floor->addWaitingCall(self::SUPPORT_QUEUE, 'call-newer', $this->createTime('09:59:30'));
        $floor->addWaitingCall(self::SUPPORT_QUEUE, 'call-older', $this->createTime('09:58:50'));
        $floor->recordAnswer(self::SUPPORT_QUEUE, 'PJSIP/205', 10);
        $floor->recordAbandon(self::SUPPORT_QUEUE, 10);
        $floor->updateExchangeConnection(true);
        $this->mapper = new WireMapper($floor);
    }

    public function testSnapshotMatchesTheCommittedContractExample(): void
    {
        self::assertSame($this->readContractExample()['snapshot'] ?? null, $this->encodeAndDecode($this->mapper->mapSnapshot()));
    }

    public function testAChangedPullMatchesTheCommittedContractExample(): void
    {
        self::assertSame(
            $this->readContractExample()['pull'] ?? null,
            $this->encodeAndDecode($this->mapper->mapChangedPull('5f0c2a9e-7', new FloorChanges(['203'], [], false, false))),
        );
    }

    public function testAFullPullCarriesTheSnapshotEntitiesAndTheSlaThreshold(): void
    {
        $snapshot = $this->mapper->mapSnapshot();

        self::assertSame(
            [
                'hash' => '5f0c2a9e-7',
                'full' => true,
                'agents' => $snapshot['agents'],
                'queues' => $snapshot['queues'],
                'exchange_connected' => true,
                'sla_threshold_seconds' => 20,
            ],
            $this->mapper->mapFullPull('5f0c2a9e-7'),
        );
    }

    /**
     * @return array<mixed>
     */
    private function readContractExample(): array
    {
        $exampleJson = file_get_contents(__DIR__ . '/../../../../contract/wire.example.json');
        self::assertIsString($exampleJson);
        $example = json_decode($exampleJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($example);

        return $example;
    }

    /**
     * @param array<mixed> $wireMessage
     */
    private function encodeAndDecode(array $wireMessage): mixed
    {
        return json_decode(json_encode($wireMessage, WireMapper::JSON_ENCODE_FLAGS), true);
    }

    private function createTime(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-03 ' . $time, new DateTimeZone('Europe/Prague'));
    }
}
