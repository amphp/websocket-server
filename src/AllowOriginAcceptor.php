<?php declare(strict_types=1);

namespace Amp\Websocket\Server;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Http\HttpStatus;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;

final class AllowOriginAcceptor implements RequestHandler
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly UnrestrictedAcceptor $acceptor;

    /**
     * @param list<string> $allowOrigins
     */
    public function __construct(
        private readonly array $allowOrigins,
        private readonly ErrorHandler $errorHandler = new Internal\UpgradeErrorHandler(),
    ) {
        $this->acceptor = new UnrestrictedAcceptor($errorHandler);
    }

    public function handleRequest(Request $request): Response
    {
        if (!\in_array($request->getHeader('origin'), $this->allowOrigins, true)) {
            return $this->errorHandler->handleError(HttpStatus::FORBIDDEN, 'Origin forbidden', $request);
        }

        return $this->acceptor->handleRequest($request);
    }
}
