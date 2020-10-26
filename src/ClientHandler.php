<?php

namespace Amp\Websocket\Server;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Websocket\Client;

interface ClientHandler
{
    /**
     * Respond to websocket handshake requests.
     *
     * If a websocket application doesn't wish to impose any special constraints on the
     * handshake it doesn't have to do anything in this method (other than return the
     * given Response object) and all handshakes will be automatically accepted.
     *
     * This method provides an opportunity to set application-specific headers, including
     * cookies, on the websocket response. Although any non-101 status code can be used
     * to reject the websocket connection it is generally recommended to use a 4xx status
     * code that is descriptive of why the handshake was rejected. You can optionally use
     * the server error handler accessible from Endpoint::getErrorHandler() to generate
     * an error response, e.g., return $endpoint->getErrorHandler()->handleError(403).
     *
     * @param Gateway  $gateway  The associated websocket endpoint to which the client is connecting.
     * @param Request  $request  The HTTP request that instigated the handshake
     * @param Response $response The switching protocol response for adding headers, etc.
     *
     * @return Response Return a Response with a status code other tha {@link Status::SWITCHING_PROTOCOLS} to deny the
     *                  handshake Request.
     */
    public function handleHandshake(Gateway $gateway, Request $request, Response $response): Response;

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
     * @param Gateway  $gateway  The associated websocket endpoint to which the client is connected.
     * @param Client   $client   The websocket client connection.
     * @param Request  $request  The HTTP request that instigated the connection.
     * @param Response $response The HTTP response sent to client to accept the connection.
     */
    public function handleClient(Gateway $gateway, Client $client, Request $request, Response $response): void;
}
