<?php

declare(strict_types=1);

namespace QueueWallboard\Daemon\LongPoll;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QueueWallboard\Daemon\Floor\FloorChanges;
use QueueWallboard\Daemon\Wire\WireMapper;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Http\Message\Response;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

final class LongPollEndpoint
{
    private int $nextHeldRequestId = 0;

    /** @var array<int, array{deferred: Deferred<ResponseInterface>, timer: TimerInterface}> */
    private array $heldRequests = [];

    public function __construct(
        private readonly LoopInterface $loop,
        private readonly ChangeHistory $changeHistory,
        private readonly WireMapper $wireMapper,
        private readonly float $longPollSeconds,
    ) {}

    /**
     * @return PromiseInterface<ResponseInterface>
     */
    public function handlePull(ServerRequestInterface $request): PromiseInterface
    {
        $currentHash = $this->changeHistory->getCurrentHash();
        $requestedHash = $request->getQueryParams()['hash'] ?? null;
        $changes = is_string($requestedHash) ? $this->changeHistory->findChangesSince($requestedHash) : null;
        if ($changes === null) {
            return resolve($this->createResponse($this->wireMapper->mapFullPull($currentHash)));
        }
        if (!$changes->isEmpty()) {
            return resolve($this->createResponse($this->wireMapper->mapChangedPull($currentHash, $changes)));
        }

        return $this->holdRequest($currentHash);
    }

    public function publishChanges(FloorChanges $changes): void
    {
        $heldHash = $this->changeHistory->getCurrentHash();
        $this->changeHistory->recordChanges($changes);
        $currentHash = $this->changeHistory->getCurrentHash();
        if ($currentHash === $heldHash || $this->heldRequests === []) {
            return;
        }
        $responseBody = $changes->isLiveStateReplaced
            ? $this->wireMapper->mapFullPull($currentHash)
            : $this->wireMapper->mapChangedPull($currentHash, $changes);
        foreach (array_keys($this->heldRequests) as $heldRequestId) {
            $this->answerHeldRequest($heldRequestId, $responseBody);
        }
    }

    public function refuseHeldRequests(): void
    {
        foreach ($this->heldRequests as $heldRequestId => ['deferred' => $deferred]) {
            $this->releaseHeldRequest($heldRequestId);
            $deferred->resolve(new Response(Response::STATUS_SERVICE_UNAVAILABLE));
        }
    }

    /**
     * @return PromiseInterface<ResponseInterface>
     */
    private function holdRequest(string $currentHash): PromiseInterface
    {
        $heldRequestId = $this->nextHeldRequestId++;
        /** @var Deferred<ResponseInterface> $deferred */
        $deferred = new Deferred(function (callable $resolve) use ($heldRequestId): void {
            $this->releaseHeldRequest($heldRequestId);
            $resolve(new Response(Response::STATUS_NO_CONTENT));
        });
        $timer = $this->loop->addTimer($this->longPollSeconds, function () use ($heldRequestId, $currentHash): void {
            $this->answerHeldRequest($heldRequestId, $this->wireMapper->mapChangedPull($currentHash, new FloorChanges([], [], false, false)));
        });
        $this->heldRequests[$heldRequestId] = ['deferred' => $deferred, 'timer' => $timer];

        return $deferred->promise();
    }

    /**
     * @param array<string, mixed> $responseBody
     */
    private function answerHeldRequest(int $heldRequestId, array $responseBody): void
    {
        $deferred = $this->heldRequests[$heldRequestId]['deferred'] ?? null;
        $this->releaseHeldRequest($heldRequestId);
        $deferred?->resolve($this->createResponse($responseBody));
    }

    private function releaseHeldRequest(int $heldRequestId): void
    {
        $timer = $this->heldRequests[$heldRequestId]['timer'] ?? null;
        if ($timer !== null) {
            $this->loop->cancelTimer($timer);
        }
        unset($this->heldRequests[$heldRequestId]);
    }

    /**
     * @param array<string, mixed> $responseBody
     */
    private function createResponse(array $responseBody): ResponseInterface
    {
        return new Response(
            Response::STATUS_OK,
            ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
            json_encode($responseBody, WireMapper::JSON_ENCODE_FLAGS),
        );
    }
}
