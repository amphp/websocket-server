<?php declare(strict_types=1);

namespace Amp\Websocket\Server;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;

final class OriginWebsocketHandshakeHandler implements WebsocketHandshakeHandler
{
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * @param list<string> $allowedOrigins
     */
    public function __construct(
        private readonly array $allowedOrigins,
        private readonly ErrorHandler $errorHandler = new DefaultErrorHandler(),
    ) {
    }

    public function handleHandshake(Request $request, Response $response): Response
    {
        if (!\in_array($request->getHeader('origin'), $this->allowedOrigins, true)) {
            return $this->errorHandler->handleError(HttpStatus::FORBIDDEN, 'Origin forbidden', $request);
        }

        return $response;
    }
}
