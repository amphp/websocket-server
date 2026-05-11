<?php declare(strict_types=1);

namespace Amp\Websocket\Server;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Http\HttpStatus;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;

/**
 * Rejects WebSocket upgrade requests whose {@code Origin} header does not match an allowed origin,
 * then delegates accepted requests to another {@see WebsocketAcceptor} (defaults to {@see Rfc6455Acceptor}).
 */
final class AllowOriginAcceptor implements WebsocketAcceptor
{
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * @param list<string> $allowOrigins
     */
    public function __construct(
        private readonly array $allowOrigins,
        private readonly ErrorHandler $errorHandler = new Internal\UpgradeErrorHandler(),
        private readonly WebsocketAcceptor $acceptor = new Rfc6455Acceptor(),
    ) {
    }

    public function handleHandshake(Request $request): Response
    {
        if (!\in_array($request->getHeader('origin'), $this->allowOrigins, true)) {
            return $this->errorHandler->handleError(HttpStatus::FORBIDDEN, 'Origin forbidden', $request);
        }

        return $this->acceptor->handleHandshake($request);
    }
}
