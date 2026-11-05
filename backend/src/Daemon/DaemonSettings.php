<?php

declare(strict_types=1);

namespace QueueWallboard\Daemon;

final readonly class DaemonSettings
{
    public function __construct(
        public float $pingIntervalSeconds,
        public float $pongTimeoutSeconds,
        public float $agentGraceSeconds,
        public float $amiReplyTimeoutSeconds,
        public float $resyncTimeoutSeconds,
        public float $amiKeepaliveIntervalSeconds,
        public float $longPollSeconds,
        public int $longPollHistoryLength,
    ) {}

    public static function createDefaults(): self
    {
        return new self(
            // arbitrary, how fast a closed page is noticed against one small message per page
            pingIntervalSeconds: 5.0,
            // two ping intervals, so one lost pong closes nothing
            pongTimeoutSeconds: 10.0,
            // long enough for a page reload to reconnect before the agent is paused
            agentGraceSeconds: 10.0,
            // arbitrary, generous for a reply on the local network
            amiReplyTimeoutSeconds: 5.0,
            // arbitrary, generous for the full QueueStatus list
            resyncTimeoutSeconds: 10.0,
            // with the reply timeout, a dead exchange link is noticed within 15 s
            amiKeepaliveIntervalSeconds: 10.0,
            // well below the 60 s idle timeout of common proxies
            longPollSeconds: 15.0,
            // a client further behind gets the full state, so this only trades memory for fewer full answers
            longPollHistoryLength: 500,
        );
    }
}
