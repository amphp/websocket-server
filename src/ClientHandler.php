<?php

namespace Amp\Websocket\Server;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Websocket\WebsocketClient;

interface ClientHandler
{
    /**
     * This method is called when a new websocket connection is established on the endpoint.
     * The method may handle all messages itself or pass the connection along to a separate
     * handler if desired. The client connection is closed when the promise returned from
     * this method resolves.
     *
     * ```
     * while ($message = $client->receive()) {
     *     $payload = $message->buffer();
     *     await($client->send('Message of length ' . strlen($payload) . 'received'));
     * }
     * ```
     *
     * @param Gateway $gateway The associated websocket endpoint to which the client is connected.
     * @param WebsocketClient $client The websocket client connection.
     * @param Request $request The HTTP request that instigated the connection.
     * @param Response $response The HTTP response sent to client to accept the connection.
     */
    public function handleClient(Gateway $gateway, WebsocketClient $client, Request $request, Response $response): void;
}
