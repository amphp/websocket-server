<?php declare(strict_types=1);

namespace Amp\Websocket\Server;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Http\HttpStatus;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use function Amp\Websocket\generateAcceptFromKey;

final class Rfc6455Acceptor implements RequestHandler
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(private readonly ErrorHandler $errorHandler = new Internal\UpgradeErrorHandler())
    {
    }

    /**
     * Respond to websocket handshake requests.
     *
     * If a websocket application doesn't wish to impose any special constraints on the
     * handshake it doesn't have to do anything beyond the request handling provided by this
     * method. All valid websocket handshakes will be accepted. It is recommended to do
     * validation of the connected client as part of the protocol implemented by the
     * {@see WebsocketClientHandler}.
     *
     * Most web applications should check the `origin` header to restrict access,
     * as websocket connections aren't subject to browser's same-origin-policy. See
     * {@see AllowOriginAcceptor} for such an implementation.
     *
     * Another implementation may delegate to this class to accept the client, then validate
     * the cookies of the request before returning the response provided by the method
     * below, or returning an error response.
     *
     * The response provided by the upgrade handler is made available to {@see WebsocketClientHandler::handleClient()}.
     *
     * @param Request $request The HTTP request that instigated the handshake
     *
     * @return Response Return a response with a status code other than
     * {@link HttpStatus::SWITCHING_PROTOCOLS} to deny the handshake request.
     */
    public function handleRequest(Request $request): Response
    {
        if ($request->getMethod() !== 'GET') {
            $response = $this->errorHandler->handleError(HttpStatus::METHOD_NOT_ALLOWED, request: $request);
            $response->setHeader('allow', 'GET');
            return $response;
        }

        if ($request->getProtocolVersion() !== '1.1') {
            $response = $this->errorHandler->handleError(HttpStatus::HTTP_VERSION_NOT_SUPPORTED, request: $request);
            $response->setHeader('upgrade', 'websocket');
            return $response;
        }

        if ('' !== $request->getBody()->buffer()) {
            return $this->errorHandler->handleError(HttpStatus::BAD_REQUEST, request: $request);
        }

        $hasUpgradeWebsocket = false;
        foreach ($request->getHeaderArray('upgrade') as $value) {
            if (\strcasecmp($value, 'websocket') === 0) {
                $hasUpgradeWebsocket = true;
                break;
            }
        }
        if (!$hasUpgradeWebsocket) {
            $response = $this->errorHandler->handleError(HttpStatus::UPGRADE_REQUIRED, request: $request);
            $response->setHeader('upgrade', 'websocket');
            return $response;
        }

        $hasConnectionUpgrade = false;
        foreach ($request->getHeaderArray('connection') as $value) {
            $values = \array_map('trim', \explode(',', $value));

            foreach ($values as $token) {
                if (\strcasecmp($token, 'upgrade') === 0) {
                    $hasConnectionUpgrade = true;
                    break;
                }
            }
        }

        if (!$hasConnectionUpgrade) {
            $reason = 'Bad Request: "Connection: Upgrade" header required';
            $response = $this->errorHandler->handleError(HttpStatus::UPGRADE_REQUIRED, $reason, $request);
            $response->setHeader('upgrade', 'websocket');
            return $response;
        }

        if (!$acceptKey = $request->getHeader('sec-websocket-key')) {
            $reason = 'Bad Request: "Sec-Websocket-Key" header required';
            return $this->errorHandler->handleError(HttpStatus::BAD_REQUEST, $reason, $request);
        }

        if (!\in_array('13', $request->getHeaderArray('sec-websocket-version'), true)) {
            $reason = 'Bad Request: Requested Websocket version unavailable';
            $response = $this->errorHandler->handleError(HttpStatus::BAD_REQUEST, $reason, $request);
            $response->setHeader('sec-websocket-version', '13');
            return $response;
        }

        return new Response(HttpStatus::SWITCHING_PROTOCOLS, [
            'connection' => 'upgrade',
            'upgrade' => 'websocket',
            'sec-websocket-accept' => generateAcceptFromKey($acceptKey),
        ]);
    }
}
