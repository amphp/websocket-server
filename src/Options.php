<?php

namespace Amp\Websocket\Server;

class Options extends \Amp\Websocket\Options
{
    private $heartbeatPeriod = 10;
    private $queuedPingLimit = 3;

    final public function getHeartbeatPeriod(): int
    {
        return $this->heartbeatPeriod;
    }

    final public function withHeartbeatPeriod(int $heartbeatPeriod): self
    {
        if ($heartbeatPeriod < 1) {
            throw new \Error('$heartbeatPeriod must be a positive integer greater than 0');
        }

        $clone = clone $this;
        $clone->heartbeatPeriod = $heartbeatPeriod;

        return $clone;
    }

    final public function getQueuedPingLimit(): int
    {
        return $this->queuedPingLimit;
    }

    final public function withQueuedPingLimit(int $queuedPingLimit): self
    {
        if ($queuedPingLimit < 1) {
            throw new \Error('$queuedPingLimit must be a positive integer greater than 0');
        }

        $clone = clone $this;
        $clone->queuedPingLimit = $queuedPingLimit;

        return $clone;
    }
}
