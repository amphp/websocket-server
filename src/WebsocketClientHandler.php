<?php declare(strict_types=1);

namespace Amp\Websocket\Server;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Websocket\WebsocketClient;

interface WebsocketClientHandler
{
    /**
     * This method is called when a new websocket connection is established on the endpoint.
     * The method may handle all messages itself or pass the connection along to a separate
     * handler if desired. The client connection is closed when this method returns.
     *
     * ```
     * while ($message = $client->receive()) {
     *     $payload = $message->buffer();
     *     $client->sendText('Message of length ' . strlen($payload) . ' received');
     * }
     * ```
     *
     * @param WebsocketClient $client The websocket client connection.
     * @param Request $request The HTTP request that instigated the connection.
     * @param Response $response The HTTP response sent to client to accept the connection.
     */
    public function handleClient(WebsocketClient $client, Request $request, Response $response): void;
}
