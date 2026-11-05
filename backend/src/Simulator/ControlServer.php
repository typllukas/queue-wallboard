<?php

declare(strict_types=1);

namespace QueueWallboard\Simulator;

use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\ServerInterface;

final class ControlServer
{
    private const string SCENARIO_PATH_PATTERN = '#^/scenario/([a-z]+)$#D';

    private ?TimerInterface $outageEndTimer = null;

    /**
     * @param non-empty-list<string> $allowedOrigins
     */
    public function __construct(
        private readonly ServerInterface $socketServer,
        private readonly CallCentre $callCentre,
        private readonly AmiServer $amiServer,
        private readonly LoopInterface $loop,
        private readonly float $exchangeOutageSeconds,
        private readonly array $allowedOrigins,
    ) {
        $httpServer = new HttpServer($this->answerRequest(...));
        $httpServer->listen($socketServer);
    }

    public function close(): void
    {
        if ($this->outageEndTimer !== null) {
            $this->loop->cancelTimer($this->outageEndTimer);
        }
        $this->socketServer->close();
    }

    private function answerRequest(ServerRequestInterface $request): Response
    {
        $requestOrigin = $request->getHeaderLine('Origin');
        $isAllowedOrigin = in_array($requestOrigin, $this->allowedOrigins, true);
        $headers = [
            'Access-Control-Allow-Origin' => $isAllowedOrigin ? $requestOrigin : $this->allowedOrigins[0],
            'Vary' => 'Origin',
        ];
        if ($request->getMethod() === 'POST' && $requestOrigin !== '' && !$isAllowedOrigin) {
            return new Response(403, $headers);
        }
        if ($request->getMethod() === 'GET' && $request->getUri()->getPath() === '/scenario') {
            return new Response(
                200,
                [...$headers, 'Content-Type' => 'application/json'],
                json_encode(['scenario' => $this->callCentre->getScenario()->value], JSON_THROW_ON_ERROR),
            );
        }
        if ($request->getMethod() === 'POST' && $request->getUri()->getPath() === '/exchange-outage') {
            if ($this->outageEndTimer !== null) {
                $this->loop->cancelTimer($this->outageEndTimer);
            }
            $this->amiServer->startOutage();
            $this->outageEndTimer = $this->loop->addTimer($this->exchangeOutageSeconds, function (): void {
                $this->outageEndTimer = null;
                $this->amiServer->endOutage();
            });

            return new Response(204, $headers);
        }
        $scenario = null;
        if ($request->getMethod() === 'POST' && preg_match(self::SCENARIO_PATH_PATTERN, $request->getUri()->getPath(), $matches) === 1) {
            $scenario = Scenario::tryFrom($matches[1]);
        }
        if ($scenario === null) {
            return new Response(404, $headers);
        }
        $this->callCentre->switchScenario($scenario);

        return new Response(204, $headers);
    }
}
