<?php declare(strict_types=1);

namespace Amp\Websocket\Server;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Future;
use Amp\Websocket\WebsocketClient;
use function Amp\async;

final class WebsocketClientGateway implements WebsocketGateway
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var array<int, WebsocketClient> Indexed by client ID. */
    private array $clients = [];

    /** @var array<int, Internal\SendQueue> Senders indexed by client ID. */
    private array $senders = [];

    public function addClient(WebsocketClient $client): void
    {
        $id = $client->getId();
        $this->clients[$id] = $client;
        $this->senders[$id] = new Internal\SendQueue($client);

        $client->onClose(function () use ($id): void {
            unset($this->clients[$id], $this->senders[$id]);
        });
    }

    public function broadcastText(string $data, array $excludedClientIds = []): Future
    {
        return $this->broadcastData($data, false, $excludedClientIds);
    }

    public function broadcastBinary(string $data, array $excludedClientIds = []): Future
    {
        return $this->broadcastData($data, true, $excludedClientIds);
    }

    private function broadcastData(string $data, bool $binary, array $excludedClientIds = []): Future
    {
        $exclusionLookup = \array_flip($excludedClientIds);

        $futures = [];
        foreach ($this->senders as $id => $sender) {
            if (isset($exclusionLookup[$id])) {
                continue;
            }
            $futures[$id] = $sender->send($data, $binary);
        }

        return async(Future\awaitAll(...), $futures);
    }

    public function multicastText(string $data, array $clientIds): Future
    {
        return $this->multicastData($data, false, $clientIds);
    }

    public function multicastBinary(string $data, array $clientIds): Future
    {
        return $this->multicastData($data, true, $clientIds);
    }

    private function multicastData(string $data, bool $binary, array $clientIds): Future
    {
        $futures = [];
        foreach ($clientIds as $id) {
            $sender = $this->senders[$id] ?? null;
            if (!$sender) {
                continue;
            }
            $futures[$id] = $sender->send($data, $binary);
        }

        return async(Future\awaitAll(...), $futures);
    }

    public function sendText(string $data, int $clientId): Future
    {
        return $this->sendData($data, false, $clientId);
    }

    public function sendBinary(string $data, int $clientId): Future
    {
        return $this->sendData($data, true, $clientId);
    }

    private function sendData(string $data, bool $binary, int $clientId): Future
    {
        $sender = $this->senders[$clientId] ?? null;
        if (!$sender) {
            return Future::complete();
        }

        return $sender->send($data, $binary);
    }

    public function getClients(): array
    {
        return $this->clients;
    }
}
