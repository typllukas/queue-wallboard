<?php

declare(strict_types=1);

namespace QueueWallboard\Daemon\WebSocket;

use JsonException;
use QueueWallboard\Daemon\Floor\PauseReason;

final readonly class ClientMessage
{
    private const string PONG = 'pong';
    private const string PAUSE = 'pause';
    private const string UNPAUSE = 'unpause';

    private function __construct(
        private string $action,
        private ?PauseReason $pauseReason,
    ) {}

    /**
     * @throws InvalidClientMessage
     */
    public static function fromJson(string $json): self
    {
        try {
            $payload = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidClientMessage('The message is not valid JSON.');
        }
        if (!is_array($payload) || !isset($payload['action']) || !is_string($payload['action'])) {
            throw new InvalidClientMessage('The message must be an object with an action.');
        }

        return match ($payload['action']) {
            self::PONG, self::UNPAUSE => new self($payload['action'], null),
            self::PAUSE => new self(self::PAUSE, self::parsePauseReason($payload['pause'] ?? null)),
            default => throw new InvalidClientMessage(sprintf('Unknown action "%s".', $payload['action'])),
        };
    }

    public function isPong(): bool
    {
        return $this->action === self::PONG;
    }

    public function isPause(): bool
    {
        return $this->action === self::PAUSE;
    }

    public function isUnpause(): bool
    {
        return $this->action === self::UNPAUSE;
    }

    public function findPauseReason(): ?PauseReason
    {
        return $this->pauseReason;
    }

    private static function parsePauseReason(mixed $pause): PauseReason
    {
        $pauseReason = is_string($pause) ? PauseReason::tryFrom($pause) : null;
        if ($pauseReason !== PauseReason::Lunch && $pauseReason !== PauseReason::Training) {
            throw new InvalidClientMessage('A pause must be "lunch" or "training".');
        }

        return $pauseReason;
    }
}
