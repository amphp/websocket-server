<?php

namespace Amp\Websocket\Server\Internal;

use Amp\DeferredFuture;
use Amp\Future;
use Amp\Websocket\Client;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;

final class AsyncSender
{
    /** @var \SplQueue<array{DeferredFuture, string, bool}> */
    private \SplQueue $writeQueue;

    private ?Suspension $suspension = null;

    private bool $active = true;

    public function __construct(Client $client)
    {
        $this->writeQueue = $writeQueue = new \SplQueue;

        $suspension = &$this->suspension;
        $active = &$this->active;
        EventLoop::queue(static function () use ($client, $writeQueue, &$suspension, &$active): void {
            while ($active && !$client->isClosed()) {
                if ($writeQueue->isEmpty()) {
                    $suspension = EventLoop::getSuspension();
                    $suspension->suspend();
                }

                while (!$writeQueue->isEmpty() && !$client->isClosed()) {
                    /**
                     * @var DeferredFuture $deferredFuture
                     * @var string $data
                     * @var bool $binary
                     */
                    [$deferredFuture, $data, $binary] = $writeQueue->shift();

                    try {
                        $binary ? $client->sendBinary($data) : $client->send($data);
                        $deferredFuture->complete();
                    } catch (\Throwable $exception) {
                        $active = false;
                        $deferredFuture->error($exception);
                        while (!$writeQueue->isEmpty()) {
                            [$deferredFuture] = $writeQueue->shift();
                            $deferredFuture->error($exception);
                        }
                        return;
                    }
                }
            }
        });
    }

    public function __destruct()
    {
        $this->active = false;
        $this->suspension?->resume();
        $this->suspension = null;
    }

    /**
     * @param string $data
     * @param bool $binary
     *
     * @return Future<void>
     */
    public function send(string $data, bool $binary): Future
    {
        $deferredFuture = new DeferredFuture();
        $this->writeQueue->push([$deferredFuture, $data, $binary]);
        $this->suspension?->resume();
        $this->suspension = null;

        return $deferredFuture->getFuture();
    }
}
