<?php

declare(strict_types=1);

namespace QueueWallboard\Daemon\Floor;

enum PauseReason: string
{
    case Lunch = 'lunch';
    case Training = 'training';
    case Inactive = 'inactive';
    case Other = 'other';

    public static function fromAmiReason(string $amiReason): self
    {
        return match ($amiReason) {
            'Lunch' => self::Lunch,
            'Training' => self::Training,
            'Inactive', 'Auto-Pause' => self::Inactive,
            default => self::Other,
        };
    }

    public function toAmiReason(): string
    {
        return match ($this) {
            self::Lunch => 'Lunch',
            self::Training => 'Training',
            self::Inactive => 'Inactive',
            self::Other => 'Other',
        };
    }
}
