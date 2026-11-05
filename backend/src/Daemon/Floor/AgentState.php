<?php

declare(strict_types=1);

namespace QueueWallboard\Daemon\Floor;

enum AgentState: string
{
    case Session = 'Session';
    case Idle = 'Idle';
    case Paused = 'Paused';
}
