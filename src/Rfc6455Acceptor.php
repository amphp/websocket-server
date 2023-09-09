<?php declare(strict_types=1);

namespace Amp\Websocket\Server;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Http\HttpStatus;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use function Amp\Websocket\generateAcceptFromKey;

final class Rfc6455Acceptor implements WebsocketAcceptor
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(private readonly ErrorHandler $errorHandler = new Internal\UpgradeErrorHandler())
    {
    }

    public function handleHandshake(Request $request): Response
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
