<?php declare(strict_types=1);

namespace Amp\Websocket\Server;

use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;

interface WebsocketHandshakeHandler
{
    /**
     * Respond to websocket handshake requests.
     *
     * If a websocket application doesn't wish to impose any special constraints on the
     * handshake it doesn't have to do anything in this method (other than return the
     * given Response object) and all handshakes will be automatically accepted. See
     * {@see UnrestrictedHandshakeHandler} for such an implementation.
     *
     * Most web applications should check the {@code origin} header to restrict access,
     * as websocket connections aren't subject to browser's same-origin-policy. See
     * {@see AllowOriginHandshakeHandler} for such an implementation.
     *
     * This method provides an opportunity to set application-specific headers, including
     * cookies, on the websocket response. Although any non-101 status code can be used
     * to reject the websocket connection, we generally recommended using a 4xx status
     * code that is descriptive of why the handshake was rejected. It is suggested that an
     * instance of {@see ErrorHandler} is used to generate such a response.
     *
     * @param Request $request The HTTP request that instigated the handshake
     * @param Response $response The switching protocol response for adding headers, etc.
     *
     * @return Response Return a response with a status code other than
     * {@link HttpStatus::SWITCHING_PROTOCOLS} to deny the handshake request.
     */
    public function handleHandshake(Request $request, Response $response): Response;
}
