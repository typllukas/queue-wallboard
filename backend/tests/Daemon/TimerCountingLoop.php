<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Daemon;

use LogicException;
use Override;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

final class TimerCountingLoop implements LoopInterface
{
    /** @var array<int, true> */
    private array $pendingTimerIds = [];

    public function countPendingTimers(): int
    {
        return count($this->pendingTimerIds);
    }

    #[Override]
    public function addReadStream($stream, $listener): void
    {
        Loop::get()->addReadStream($stream, $listener);
    }

    #[Override]
    public function addWriteStream($stream, $listener): void
    {
        Loop::get()->addWriteStream($stream, $listener);
    }

    #[Override]
    public function removeReadStream($stream): void
    {
        Loop::get()->removeReadStream($stream);
    }

    #[Override]
    public function removeWriteStream($stream): void
    {
        Loop::get()->removeWriteStream($stream);
    }

    #[Override]
    public function addTimer($interval, $callback): TimerInterface
    {
        $timer = Loop::get()->addTimer($interval, function (TimerInterface $firedTimer) use ($callback): void {
            unset($this->pendingTimerIds[spl_object_id($firedTimer)]);
            $callback($firedTimer);
        });
        $this->pendingTimerIds[spl_object_id($timer)] = true;

        return $timer;
    }

    #[Override]
    public function addPeriodicTimer($interval, $callback): TimerInterface
    {
        $timer = Loop::get()->addPeriodicTimer($interval, $callback);
        $this->pendingTimerIds[spl_object_id($timer)] = true;

        return $timer;
    }

    #[Override]
    public function cancelTimer(TimerInterface $timer): void
    {
        unset($this->pendingTimerIds[spl_object_id($timer)]);
        Loop::get()->cancelTimer($timer);
    }

    #[Override]
    public function futureTick($listener): void
    {
        Loop::get()->futureTick($listener);
    }

    #[Override]
    public function addSignal($signal, $listener): void
    {
        Loop::get()->addSignal($signal, $listener);
    }

    #[Override]
    public function removeSignal($signal, $listener): void
    {
        Loop::get()->removeSignal($signal, $listener);
    }

    #[Override]
    public function run(): void
    {
        throw new LogicException('Tests run the global loop through React\Async, never by hand.');
    }

    #[Override]
    public function stop(): void
    {
        throw new LogicException('Tests run the global loop through React\Async, never by hand.');
    }
}
