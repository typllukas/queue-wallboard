<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Simulator;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueueWallboard\Ami\AmiPacket;
use QueueWallboard\Simulator\AmiServer;
use QueueWallboard\Simulator\CallCentre;
use QueueWallboard\Simulator\Floor;
use QueueWallboard\Simulator\SimulationTimings;
use Random\Engine\Mt19937;
use Random\Randomizer;
use React\EventLoop\Loop;
use React\Socket\SocketServer;
use Symfony\Component\Clock\MockClock;

use function React\Async\delay;

#[CoversClass(AmiServer::class)]
final class AmiServerTest extends TestCase
{
    private const string USERNAME = 'demo';
    private const string SECRET = 'demo-secret';

    private CallCentre $callCentre;
    private SocketServer $socketServer;
    private AmiServer $amiServer;

    /** @var list<AmiTestClient> */
    private array $clients = [];

    #[Override]
    protected function setUp(): void
    {
        $this->callCentre = new CallCentre(
            Floor::createDemoFloor(),
            SimulationTimings::createDemoTimings(),
            Loop::get(),
            new Randomizer(new Mt19937(1)),
            new MockClock('2026-11-03 10:00:00'),
        );
        $this->socketServer = new SocketServer('127.0.0.1:0');
        $this->amiServer = new AmiServer($this->socketServer, $this->callCentre, self::USERNAME, self::SECRET);
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }
        $this->amiServer->close();
        $this->callCentre->stop();
    }

    public function testLoginIsAcceptedAfterTheBanner(): void
    {
        $client = $this->connectClient();

        self::assertSame("Asterisk Call Manager/11.0.0\r\n", $client->awaitBanner());
        $client->send(['Action' => 'Login', 'ActionID' => 'login-1', 'Username' => self::USERNAME, 'Secret' => self::SECRET]);
        $reply = $client->awaitPackets(1)[0];

        self::assertTrue($reply->isSuccessResponse());
        self::assertSame('login-1', $reply->findActionId());
        self::assertSame('Authentication accepted', $reply->getField('Message'));
    }

    public function testLoginWithAWrongSecretIsRefusedAndClosesTheConnection(): void
    {
        $client = $this->connectClient();

        $client->send(['Action' => 'Login', 'Username' => self::USERNAME, 'Secret' => 'wrong']);
        $reply = $client->awaitPackets(1)[0];
        $client->awaitClose();

        self::assertSame('Error', $reply->findField('Response'));
        self::assertSame('Authentication failed', $reply->getField('Message'));
    }

    public function testPingIsAnsweredWithPong(): void
    {
        $client = $this->connectLoggedInClient();

        $client->send(['Action' => 'Ping', 'ActionID' => 'ping-1']);
        $reply = $client->awaitPackets(1)[0];

        self::assertTrue($reply->isSuccessResponse());
        self::assertSame('ping-1', $reply->findActionId());
        self::assertSame('Pong', $reply->getField('Ping'));
        self::assertMatchesRegularExpression('/^\d+\.\d{6}$/D', $reply->getField('Timestamp'));
    }

    public function testQueuePauseRepliesSuccessAndEmitsQueueMemberPause(): void
    {
        $client = $this->connectLoggedInClient();

        $client->send(['Action' => 'QueuePause', 'ActionID' => 'pause-1', 'Interface' => 'PJSIP/203', 'Paused' => 'true', 'Reason' => 'Lunch']);
        $packets = $client->awaitPackets(3);

        $reply = $packets[2];
        self::assertTrue($reply->isSuccessResponse());
        self::assertSame('pause-1', $reply->findActionId());
        self::assertSame('Interface paused successfully', $reply->getField('Message'));
        $pausedQueueNames = [];
        foreach ($packets as $packet) {
            if ($packet->findEventName() === 'QueueMemberPause') {
                $pausedQueueNames[] = $packet->getField('Queue');
                self::assertSame('Lunch', $packet->getField('PausedReason'));
                self::assertNull($packet->findActionId());
            }
        }
        self::assertSame([Floor::SALES_QUEUE, Floor::SUPPORT_QUEUE], $pausedQueueNames);
    }

    public function testQueuePauseForAnUnknownMemberRepliesError(): void
    {
        $client = $this->connectLoggedInClient();

        $client->send(['Action' => 'QueuePause', 'ActionID' => 'pause-2', 'Interface' => 'PJSIP/999', 'Paused' => 'true']);
        $reply = $client->awaitPackets(1)[0];

        self::assertSame('Error', $reply->findField('Response'));
        self::assertSame('pause-2', $reply->findActionId());
        self::assertSame('Interface not found', $reply->getField('Message'));
    }

    public function testQueueStatusRepliesWithAStartedEventList(): void
    {
        $client = $this->connectLoggedInClient();

        $client->send(['Action' => 'QueueStatus', 'ActionID' => 'status-1']);
        $packets = $client->awaitPackets(1 + count($this->callCentre->describeQueueStatus('status-1')));

        self::assertTrue($packets[0]->isSuccessResponse());
        self::assertSame('start', $packets[0]->getField('EventList'));
        self::assertSame('status-1', $packets[0]->findActionId());
        self::assertSame('QueueParams', $packets[1]->findEventName());
        $lastPacket = $packets[count($packets) - 1];
        self::assertSame('QueueStatusComplete', $lastPacket->findEventName());
        self::assertSame('status-1', $lastPacket->findActionId());
    }

    public function testAnActionBeforeLoginIsRefused(): void
    {
        $client = $this->connectClient();

        $client->send(['Action' => 'Ping', 'ActionID' => 'ping-2']);
        $reply = $client->awaitPackets(1)[0];

        self::assertSame('Error', $reply->findField('Response'));
        self::assertSame('ping-2', $reply->findActionId());
        self::assertSame('Permission denied', $reply->getField('Message'));
    }

    public function testAnUnsupportedActionRepliesWithAnUnknownCommandError(): void
    {
        $client = $this->connectLoggedInClient();

        $client->send(['Action' => 'Hangup', 'ActionID' => 'other-1']);
        $reply = $client->awaitPackets(1)[0];

        self::assertSame('Error', $reply->findField('Response'));
        self::assertSame('Invalid/unknown command', $reply->getField('Message'));
    }

    public function testAMalformedPacketRepliesWithAnErrorAndClosesTheConnection(): void
    {
        $client = $this->connectClient();

        $client->sendRaw("this is not a packet\r\n\r\n");
        $reply = $client->awaitPackets(1)[0];
        $client->awaitClose();

        self::assertSame('Error', $reply->findField('Response'));
        self::assertSame('Malformed packet', $reply->getField('Message'));
    }

    public function testEventsAreNotSentBeforeLogin(): void
    {
        $client = $this->connectClient();
        $client->awaitBanner();

        $this->callCentre->pauseMember('PJSIP/203', true, 'Lunch');
        delay(0.1);

        self::assertSame([], $client->listReceivedPackets());
    }

    public function testEventsAreSentAfterLoginAndStopWhenTheConnectionCloses(): void
    {
        $client = $this->connectLoggedInClient();

        $this->callCentre->pauseMember('PJSIP/203', true, 'Lunch');
        $eventNames = array_map(static fn(AmiPacket $packet): ?string => $packet->findEventName(), $client->awaitPackets(2));
        $listenerCountWhileConnected = count($this->callCentre->listeners('event'));
        $client->close();
        delay(0.05);

        self::assertSame(['QueueMemberPause', 'QueueMemberPause'], $eventNames);
        self::assertSame(1, $listenerCountWhileConnected);
        self::assertSame([], $this->callCentre->listeners('event'));
    }

    public function testAnOutageClosesTheSessionsAndRefusesConnectionsUntilItEnds(): void
    {
        $loggedInClient = $this->connectLoggedInClient();

        $this->amiServer->startOutage();
        $loggedInClient->awaitClose();
        $clientDuringOutage = $this->connectClient();
        $clientDuringOutage->awaitClose();
        $this->amiServer->endOutage();
        $clientAfterOutage = $this->connectClient();

        self::assertFalse($loggedInClient->isOpen());
        self::assertFalse($clientDuringOutage->isOpen());
        self::assertSame("Asterisk Call Manager/11.0.0\r\n", $clientAfterOutage->awaitBanner());
    }

    private function connectClient(): AmiTestClient
    {
        $client = AmiTestClient::connect($this->socketServer->getAddress() ?? '');
        $this->clients[] = $client;

        return $client;
    }

    private function connectLoggedInClient(): AmiTestClient
    {
        $client = $this->connectClient();
        $client->send(['Action' => 'Login', 'Username' => self::USERNAME, 'Secret' => self::SECRET]);
        $client->awaitPackets(1);

        return $client;
    }
}
