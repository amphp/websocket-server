<?php

namespace Amp\Websocket\Server;

use Amp\Future;
use Amp\Websocket\WebsocketClient;

/**
 * A gateway provides methods to asynchronously send messages to a collection of clients connected to one or more
 * websocket endpoints. When sending the same message to multiple clients, it is recommended that an implementation
 * such as {@see WebsocketClientGateway} is used instead of iteration over a set of clients. Sending to clients
 * individually may result in slow consuming clients (i.e., clients with messages in their send buffer) delaying
 * sending messages to other clients.
 *
 * Messages sent via a gateway may arrive at the websocket client out of order of those sent directly using
 * {@see WebsocketClient::send()} or {@see WebsocketClient::sendBinary()}. If ordering of broadcast or multicast
 * messages must be maintained with messages sent only to individual clients, always use the {@see sendAsync()} and
 * {@see sendBinaryAsync()} methods on implementations of this interface to send to individual clients.
 */
interface WebsocketGateway
{
    /**
     * Broadcast a UTF-8 text message to all clients (except those given in the optional array).
     *
     * @param string $data Data to send.
     * @param int[] $excludedClientIds List of IDs to exclude from the broadcast.
     *
     * @return Future<array{array<int, \Throwable>, array<int, null>}> Completes once the message has been sent to all
     * clients. The completion value is an array containing two arrays: an array of exceptions indexed by client ID of
     * sends that failed and an array with keys corresponding to client IDs of successful sends.
     * Note it is generally undesirable to await this future in a coroutine.
     *
     * @see Future\awaitAll() Completion array corresponds to the return of this function.
     */
    public function broadcast(string $data, array $excludedClientIds = []): Future;

    /**
     * Send a binary message to all clients (except those given in the optional array).
     *
     * @param string $data Data to send.
     * @param int[] $excludedClientIds List of IDs to exclude from the broadcast.
     *
     * @return Future<array{array<int, \Throwable>, array<int, null>}> Completes once the message has been sent to all
     * clients. The completion value is an array containing two arrays: an array of exceptions indexed by client ID of
     * sends that failed and an array with keys corresponding to client IDs of successful sends.
     * Note it is generally undesirable to await this future in a coroutine.
     *
     * @see Future\awaitAll() Completion array corresponds to the return of this function.
     */
    public function broadcastBinary(string $data, array $excludedClientIds = []): Future;

    /**
     * Send a UTF-8 text message to a set of clients.
     *
     * @param string $data Data to send.
     * @param int[] $clientIds Array of client IDs.
     *
     * @return Future<array{array<int, \Throwable>, array<int, null>}> Completes once the message has been sent to all
     * clients. The completion value is an array containing two arrays: an array of exceptions indexed by client ID of
     * sends that failed and an array with keys corresponding to client IDs of successful sends.
     * Note it is generally undesirable to await this future in a coroutine.
     *
     * @see Future\awaitAll() Completion array corresponds to the return of this function.
     */
    public function multicast(string $data, array $clientIds): Future;

    /**
     * Send a binary message to a set of clients.
     *
     * @param string $data Data to send.
     * @param int[] $clientIds Array of client IDs.
     *
     * @return Future<array{array<int, \Throwable>, array<int, null>}> Completes once the message has been sent to all
     * clients. The completion value is an array containing two arrays: an array of exceptions indexed by client ID of
     * sends that failed and an array with keys corresponding to client IDs of successful sends.
     * Note it is generally undesirable to await this future in a coroutine.
     *
     * @see Future\awaitAll() Completion array corresponds to the return of this function.
     */
    public function multicastBinary(string $data, array $clientIds): Future;

    /**
     * Send a UTF-8 text data to a single client, returning a future immediately instead of waiting to return until the
     * data is sent as {@see WebsocketClient::send()}. This method guarantees ordering with broadcast or multicast
     * messages.
     *
     * @return Future<void>
     */
    public function send(string $data, int $clientId): Future;

    /**
     * Send binary data to a single client, returning a future immediately instead of waiting to return until the data
     * is sent as {@see WebsocketClient::sendBinary()}. This method guarantees ordering with broadcast or multicast
     * messages.
     *
     * @return Future<void>
     */
    public function sendBinary(string $data, int $clientId): Future;

    /**
     * @return array<int, WebsocketClient> Array of {@see WebsocketClient} objects currently connected to this endpoint
     * indexed by their IDs.
     */
    public function getClients(): array;

    /**
     * Add a client to this Gateway.
     */
    public function addClient(WebsocketClient $client): void;
}
