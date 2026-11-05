<?php

declare(strict_types=1);

namespace QueueWallboard\Daemon\LongPoll;

use QueueWallboard\Daemon\Floor\FloorChanges;

final class ChangeHistory
{
    private int $currentVersion = 0;
    private int $oldestKnownVersion = 0;

    /** @var array<int, FloorChanges> */
    private array $changesByVersion = [];

    public function __construct(
        private readonly string $bootId,
        private readonly int $historyLength,
    ) {}

    public function recordChanges(FloorChanges $changes): void
    {
        if ($changes->isEmpty()) {
            return;
        }
        $this->currentVersion++;
        if ($changes->isLiveStateReplaced) {
            $this->changesByVersion = [];
            $this->oldestKnownVersion = $this->currentVersion;

            return;
        }
        $this->changesByVersion[$this->currentVersion] = $changes;
        if (count($this->changesByVersion) > $this->historyLength) {
            $this->oldestKnownVersion = array_key_first($this->changesByVersion);
            unset($this->changesByVersion[$this->oldestKnownVersion]);
        }
    }

    public function findChangesSince(string $hash): ?FloorChanges
    {
        $versionText = str_starts_with($hash, $this->bootId . '-') ? substr($hash, strlen($this->bootId) + 1) : '';
        if (!ctype_digit($versionText)) {
            return null;
        }
        $version = intval($versionText);
        if ($version > $this->currentVersion || $version < $this->oldestKnownVersion) {
            return null;
        }

        $agentExtensions = [];
        $queueNames = [];
        $isExchangeConnectionChanged = false;
        foreach ($this->changesByVersion as $changeVersion => $changes) {
            if ($changeVersion <= $version) {
                continue;
            }
            foreach ($changes->agentExtensions as $extension) {
                $agentExtensions[$extension] = true;
            }
            foreach ($changes->queueNames as $queueName) {
                $queueNames[$queueName] = true;
            }
            $isExchangeConnectionChanged = $isExchangeConnectionChanged || $changes->isExchangeConnectionChanged;
        }

        return new FloorChanges(
            array_map(strval(...), array_keys($agentExtensions)),
            array_map(strval(...), array_keys($queueNames)),
            $isExchangeConnectionChanged,
            false,
        );
    }

    public function getCurrentHash(): string
    {
        return $this->bootId . '-' . $this->currentVersion;
    }
}
