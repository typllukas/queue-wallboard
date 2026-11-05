<?php

declare(strict_types=1);

namespace QueueWallboard\Daemon;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QueueWallboard\Daemon\LongPoll\LongPollEndpoint;
use QueueWallboard\Daemon\WebSocket\WebSocketServer;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;

final readonly class HttpRouter
{
    public function __construct(
        private WebSocketServer $webSocketServer,
        private LongPollEndpoint $longPollEndpoint,
    ) {}

    /**
     * @return ResponseInterface|PromiseInterface<ResponseInterface>
     */
    public function handleRequest(ServerRequestInterface $request): ResponseInterface|PromiseInterface
    {
        return match ($request->getUri()->getPath()) {
            '/websocket' => $this->webSocketServer->handleUpgrade($request),
            '/pull' => $this->longPollEndpoint->handlePull($request),
            default => new Response(Response::STATUS_NOT_FOUND),
        };
    }
}
