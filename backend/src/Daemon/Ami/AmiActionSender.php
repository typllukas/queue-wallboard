<?php

declare(strict_types=1);

namespace QueueWallboard\Daemon\Ami;

use QueueWallboard\Ami\AmiPacket;
use React\Promise\PromiseInterface;

interface AmiActionSender
{
    /**
     * @param array<string, string> $fields
     * @return PromiseInterface<AmiPacket>
     */
    public function sendAction(string $action, array $fields): PromiseInterface;
}
