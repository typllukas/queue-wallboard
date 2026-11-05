<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Daemon;

use Override;
use QueueWallboard\Ami\AmiPacket;
use QueueWallboard\Daemon\Ami\AmiActionSender;
use React\Promise\PromiseInterface;
use Throwable;

use function React\Promise\reject;
use function React\Promise\resolve;

final class RecordingAmiActionSender implements AmiActionSender
{
    /** @var list<array{action: string, fields: array<string, string>}> */
    public private(set) array $sentActions = [];

    private ?Throwable $failure = null;

    private ?int $remainingFailingActionCount = null;

    public function failEveryActionWith(Throwable $failure): void
    {
        $this->failure = $failure;
        $this->remainingFailingActionCount = null;
    }

    public function failNextActionsWith(Throwable $failure, int $failingActionCount): void
    {
        $this->failure = $failure;
        $this->remainingFailingActionCount = $failingActionCount;
    }

    #[Override]
    public function sendAction(string $action, array $fields): PromiseInterface
    {
        $this->sentActions[] = ['action' => $action, 'fields' => $fields];
        if ($this->failure !== null && $this->remainingFailingActionCount !== 0) {
            if ($this->remainingFailingActionCount !== null) {
                --$this->remainingFailingActionCount;
            }

            return reject($this->failure);
        }

        return resolve(new AmiPacket(['Response' => 'Success']));
    }
}
