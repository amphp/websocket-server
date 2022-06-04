<?php

namespace Amp\Websocket\Server\Internal;

use Amp\DeferredFuture;
use Amp\Future;
use Amp\Websocket\WebsocketClient;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;

/** @internal */
final class AsyncSender
{
    /** @var \SplQueue<array{DeferredFuture, string, bool}> */
    private \SplQueue $writeQueue;

    private ?Suspension $suspension = null;

    private ?WebsocketClient $client;

    public function __construct(WebsocketClient $client)
    {
        $this->client = $client;
        $this->writeQueue = $writeQueue = new \SplQueue;

        $suspension = &$this->suspension;
        $destination = &$this->client;
        EventLoop::queue(static function () use ($writeQueue, &$suspension, &$destination): void {
            while ($destination && !$destination->isClosed()) {
                if ($writeQueue->isEmpty()) {
                    $suspension = EventLoop::getSuspension();
                    if (!$suspension->suspend()) {
                        return;
                    }
                }

                self::dequeue($writeQueue, $destination);
            }
        });
    }

    private static function dequeue(\SplQueue $writeQueue, WebsocketClient $client): void
    {
        while (!$writeQueue->isEmpty() && !$client->isClosed()) {
            /**
             * @var DeferredFuture $deferredFuture
             * @var string $data
             * @var bool $binary
             */
            [$deferredFuture, $data, $binary] = $writeQueue->dequeue();

            try {
                $binary ? $client->sendBinary($data) : $client->send($data);
                $deferredFuture->complete();
            } catch (\Throwable $exception) {
                $deferredFuture->error($exception);
                while (!$writeQueue->isEmpty()) {
                    [$deferredFuture] = $writeQueue->dequeue();
                    $deferredFuture->error($exception);
                }
                return;
            }
        }
    }

    public function __destruct()
    {
        $this->client = null;
        $this->suspension?->resume(false);
        $this->suspension = null;
    }

    /**
     * @return Future<void>
     */
    public function send(string $data, bool $binary): Future
    {
        $deferredFuture = new DeferredFuture();
        $this->writeQueue->enqueue([$deferredFuture, $data, $binary]);
        $this->suspension?->resume(true);
        $this->suspension = null;

        return $deferredFuture->getFuture();
    }
}
