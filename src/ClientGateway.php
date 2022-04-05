<?php

namespace Amp\Websocket\Server;

use Amp\Future;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Websocket\Client;
use Amp\Websocket\ClientMetadata;
use function Amp\async;

final class ClientGateway implements Gateway
{
    /** @var array<int, Client> Indexed by client ID. */
    private array $clients = [];

    /** @var array<int, Internal\AsyncSender> Senders indexed by client ID. */
    private array $senders = [];

    public function addClient(Client $client, Request $request, Response $response): void
    {
        $id = $client->getId();
        $this->clients[$id] = $client;
        $this->senders[$id] = new Internal\AsyncSender($client);

        $client->onClose(function (ClientMetadata $metadata): void {
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

        return async(static fn () => Future\settle($futures));
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
        return async(static fn () => Future\settle($futures));
    }

    /**
     * Send a binary message to a set of clients.
     *
     * @param string $data Data to send.
     * @param int[] $clientIds Array of client IDs.
     *
     * @return Future<array> Resolves once the message has been sent to all clients. Note it is
     *                       generally undesirable to await this future in a coroutine.
     */
    public function multicastBinary(string $data, array $clientIds): Future
    {
        return $this->multicastData($data, true, $clientIds);
    }

    /**
     * @return Client[] Array of Client objects currently connected to this endpoint indexed by their IDs.
     */
    public function getClients(): array
    {
        return $this->clients;
    }
}
