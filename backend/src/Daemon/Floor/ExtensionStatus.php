<?php

declare(strict_types=1);

namespace QueueWallboard\Daemon\Floor;

use ValueError;

enum ExtensionStatus: string
{
    case Unavailable = 'Unavailable';
    case Idle = 'Idle';
    case Busy = 'Busy';

    public static function fromDeviceState(int $deviceState): self
    {
        // Asterisk device states: 1 not in use; 2, 3, 6 (ringing), 7, 8 line taken; 0, 4, 5 no usable line.
        return match ($deviceState) {
            1 => self::Idle,
            2, 3, 6, 7, 8 => self::Busy,
            0, 4, 5 => self::Unavailable,
            default => throw new ValueError(sprintf('Unknown Asterisk device state %d.', $deviceState)),
        };
    }
}
