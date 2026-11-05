<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Simulator;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use QueueWallboard\Simulator\AmiServer;
use QueueWallboard\Simulator\CallCentre;
use QueueWallboard\Simulator\ControlServer;
use QueueWallboard\Simulator\Floor;
use QueueWallboard\Simulator\Scenario;
use QueueWallboard\Simulator\SimulationTimings;
use Random\Engine\Mt19937;
use Random\Randomizer;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Socket\SocketServer;
use Symfony\Component\Clock\MockClock;

use function React\Async\await;
use function React\Async\delay;

#[CoversClass(ControlServer::class)]
final class ControlServerTest extends TestCase
{
    private const float EXCHANGE_OUTAGE_SECONDS = 0.1;

    private CallCentre $callCentre;
    private SocketServer $controlSocketServer;
    private SocketServer $amiSocketServer;
    private AmiServer $amiServer;
    private ControlServer $controlServer;

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
        $this->callCentre->start(Scenario::Normal);
        $this->controlSocketServer = new SocketServer('127.0.0.1:0');
        $this->amiSocketServer = new SocketServer('127.0.0.1:0');
        $this->amiServer = new AmiServer($this->amiSocketServer, $this->callCentre, 'demo', 'demo-secret');
        $this->controlServer = new ControlServer($this->controlSocketServer, $this->callCentre, $this->amiServer, Loop::get(), self::EXCHANGE_OUTAGE_SECONDS, ['http://localhost:3000', 'http://127.0.0.1:3000']);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->controlServer->close();
        $this->amiServer->close();
        $this->callCentre->stop();
    }

    public function testControlSwitchesTheScenario(): void
    {
        $response = $this->post('/scenario/peak');

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(Scenario::Peak, $this->callCentre->getScenario());
    }

    public function testControlRefusesAnUnknownScenario(): void
    {
        $response = $this->post('/scenario/stormy');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(Scenario::Normal, $this->callCentre->getScenario());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideScenarioPaths(): array
    {
        return [
            'known scenario' => ['/scenario/quiet'],
            'unknown scenario' => ['/scenario/stormy'],
        ];
    }

    #[DataProvider('provideScenarioPaths')]
    public function testControlNamesTheFirstAllowedOriginWhenTheRequestHasNone(string $path): void
    {
        self::assertSame(['http://localhost:3000'], $this->post($path)->getHeader('Access-Control-Allow-Origin'));
    }

    public function testControlAllowsTheDemoPageOpenedOnTheLoopbackAddress(): void
    {
        $response = $this->post('/scenario/peak', 'http://127.0.0.1:3000');

        self::assertSame(['http://127.0.0.1:3000'], $response->getHeader('Access-Control-Allow-Origin'));
        self::assertSame(['Origin'], $response->getHeader('Vary'));
    }

    public function testControlRefusesAChangeFromAForeignWebsite(): void
    {
        $scenarioResponse = $this->post('/scenario/peak', 'http://evil.example');
        $outageResponse = $this->post('/exchange-outage', 'http://evil.example');
        $client = AmiTestClient::connect($this->amiSocketServer->getAddress() ?? '');

        self::assertSame(403, $scenarioResponse->getStatusCode());
        self::assertSame(403, $outageResponse->getStatusCode());
        self::assertSame(Scenario::Normal, $this->callCentre->getScenario());
        self::assertSame("Asterisk Call Manager/11.0.0\r\n", $client->awaitBanner());
        $client->close();
    }

    public function testControlDoesNotEchoAForeignOrigin(): void
    {
        $response = await(new Browser()->get($this->formatControlUrl() . '/scenario', ['Origin' => 'http://evil.example']));

        self::assertSame(['http://localhost:3000'], $response->getHeader('Access-Control-Allow-Origin'));
    }

    public function testControlReportsTheRunningScenario(): void
    {
        $this->post('/scenario/peak');

        $response = await(new Browser()->get($this->formatControlUrl() . '/scenario', ['Origin' => 'http://127.0.0.1:3000']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['application/json'], $response->getHeader('Content-Type'));
        self::assertSame(['http://127.0.0.1:3000'], $response->getHeader('Access-Control-Allow-Origin'));
        self::assertSame(['scenario' => 'peak'], json_decode(strval($response->getBody()), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testControlStartsAnExchangeOutageThatEndsByItself(): void
    {
        $response = $this->post('/exchange-outage');
        $amiAddress = $this->amiSocketServer->getAddress() ?? '';
        $clientDuringOutage = AmiTestClient::connect($amiAddress);
        $clientDuringOutage->awaitClose();
        delay(self::EXCHANGE_OUTAGE_SECONDS);
        $clientAfterOutage = AmiTestClient::connect($amiAddress);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(['http://localhost:3000'], $response->getHeader('Access-Control-Allow-Origin'));
        self::assertFalse($clientDuringOutage->isOpen());
        self::assertSame("Asterisk Call Manager/11.0.0\r\n", $clientAfterOutage->awaitBanner());
        $clientAfterOutage->close();
    }

    private function formatControlUrl(): string
    {
        return str_replace('tcp://', 'http://', $this->controlSocketServer->getAddress() ?? '');
    }

    private function post(string $path, ?string $origin = null): ResponseInterface
    {
        $headers = $origin === null ? [] : ['Origin' => $origin];

        return await(new Browser()->withRejectErrorResponse(false)->post($this->formatControlUrl() . $path, $headers));
    }
}
