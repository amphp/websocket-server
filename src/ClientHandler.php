<?php

namespace Amp\Websocket\Server;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Promise;
use Amp\Websocket\Client;

interface ClientHandler
{
    /**
     * Called when the HTTP server is started.
     *
     * @param Websocket $endpoint
     *
     * @return Promise
     */
    public function onStart(Websocket $endpoint): Promise;

    /**
     * Called when the HTTP server is stopped.
     *
     * @param Websocket $endpoint
     *
     * @return Promise
     */
    public function onStop(Websocket $endpoint): Promise;

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
     * code that is descriptive of why the handshake was rejected.
     *
     * @param Request  $request The HTTP request that instigated the handshake
     * @param Response $response The switching protocol response for adding headers, etc.
     *
     * @return Promise<Response> Resolve the Promise with a Response set to a status code
     *                           other than {@link Status::SWITCHING_PROTOCOLS} to deny the
     *                           handshake Request.
     */
    public function handleHandshake(Request $request, Response $response): Promise;

    /**
     * This method is called when a new websocket connection is established on the endpoint.
     * The method may handle all messages itself or pass the connection along to a separate
     * handler if desired.
     *
     * ```
     * return Amp\call(function () use ($client) {
     *     while ($message = yield $client->receive()) {
     *         $payload = yield $message->buffer();
     *         yield $client->send('Message of length ' . \strlen($payload) . 'received');
     *     }
     * });
     * ```
     *
     * @param Client   $client The websocket client connection.
     * @param Request  $request The HTTP request that instigated the connection.
     * @param Response $response The HTTP response sent to client to accept the connection.
     *
     * @return Promise<null>
     */
    public function handleClient(Client $client, Request $request, Response $response): Promise;
}
