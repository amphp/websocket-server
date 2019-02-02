<?php

namespace Amp\Websocket\Server;

class Options extends \Amp\Websocket\Options
{
    private $heartbeatPeriod = 10;
    private $queuedPingLimit = 3;

    public function getHeartbeatPeriod(): int
    {
        return $this->heartbeatPeriod;
    }

    public function withHeartbeatPeriod(int $heartbeatPeriod): self
    {
        if ($heartbeatPeriod < 1) {
            throw new \Error('$heartbeatPeriod must be a positive integer greater than 0');
        }

        $clone = clone $this;
        $clone->heartbeatPeriod = $heartbeatPeriod;

        return $clone;
    }

    public function getQueuedPingLimit(): int
    {
        return $this->queuedPingLimit;
    }

    public function withQueuedPingLimit(int $queuedPingLimit): self
    {
        if ($queuedPingLimit < 1) {
            throw new \Error('$queuedPingLimit must be a positive integer greater than 0');
        }

        $clone = clone $this;
        $clone->queuedPingLimit = $queuedPingLimit;

        return $clone;
    }
}
