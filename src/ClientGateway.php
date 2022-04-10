<?php

namespace Amp\Websocket\Server;

use Amp\Future;
use Amp\Websocket\WebsocketClient;
use Amp\Websocket\WebsocketClientMetadata;
use function Amp\async;

final class ClientGateway implements Gateway
{
    /** @var array<int, WebsocketClient> Indexed by client ID. */
    private array $clients = [];

    /** @var array<int, Internal\AsyncSender> Senders indexed by client ID. */
    private array $senders = [];

    public function addClient(WebsocketClient $client): void
    {
        $id = $client->getId();
        $this->clients[$id] = $client;
        $this->senders[$id] = new Internal\AsyncSender($client);

        $client->onClose(function (WebsocketClientMetadata $metadata): void {
            $id = $metadata->id;
            unset($this->clients[$id], $this->senders[$id]);
        });
    }

    public function broadcast(string $data, array $exceptIds = []): Future
    {
        return $this->broadcastData($data, false, $exceptIds);
    }

    private function broadcastData(string $data, bool $binary, array $exceptIds = []): Future
    {
        $exceptIdLookup = \array_flip($exceptIds);

        /** @psalm-suppress DocblockTypeContradiction array_flip() can return null. */
        if ($exceptIdLookup === null) {
            throw new \Error('Unable to array_flip() the passed IDs');
        }

        $futures = [];
        foreach ($this->senders as $id => $sender) {
            if (isset($exceptIdLookup[$id])) {
                continue;
            }
            $futures[$id] = $sender->send($data, $binary);
        }

        return async(Future\awaitAll(...), $futures);
    }

    public function broadcastBinary(string $data, array $exceptIds = []): Future
    {
        return $this->broadcastData($data, true, $exceptIds);
    }

    public function multicast(string $data, array $clientIds): Future
    {
        return $this->multicastData($data, false, $clientIds);
    }

    private function multicastData(string $data, bool $binary, array $clientIds): Future
    {
        $futures = [];
        foreach ($clientIds as $id) {
            if (!isset($this->senders[$id])) {
                continue;
            }
            $sender = $this->senders[$id];
            $futures[$id] = $sender->send($data, $binary);
        }

        return async(Future\awaitAll(...), $futures);
    }

    public function multicastBinary(string $data, array $clientIds): Future
    {
        return $this->multicastData($data, true, $clientIds);
    }

    public function getClients(): array
    {
        return $this->clients;
    }
}
