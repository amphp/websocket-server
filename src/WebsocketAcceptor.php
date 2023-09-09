<?php declare(strict_types=1);

namespace Amp\Websocket\Server;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;

interface WebsocketAcceptor
{
    /**
     * Respond to websocket handshake requests.
     *
     * If a websocket application doesn't wish to impose any special constraints on the
     * handshake it may use {@see Rfc6455Acceptor} to accept websocket requests on a
     * {@see Websocket} endpoint.
     *
     * Most web applications should check the `origin` header to restrict access,
     * as websocket connections aren't subject to browser's same-origin-policy. See
     * {@see AllowOriginAcceptor} for such an implementation.
     *
     * This method provides an opportunity to set application-specific headers, including
     * cookies, on the websocket response. Although any non-101 status code can be used
     * to reject the websocket connection, we generally recommended using a 4xx status
     * code that is descriptive of why the handshake was rejected. It is suggested that an
     * instance of {@see ErrorHandler} is used to generate such a response.
     *
     * The response provided by the upgrade handler is made available to
     * {@see WebsocketClientHandler::handleClient()}.
     *
     * @param Request $request The websocket HTTP handshake request.
     *
     * @return Response Return a response with a status code other than
     *      {@link HttpStatus::SWITCHING_PROTOCOLS} to deny the handshake request.
     */
    public function handleHandshake(Request $request): Response;
}
