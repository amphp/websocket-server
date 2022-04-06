<?php

namespace Amp\Websocket\Server;

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use function Amp\Websocket\generateAcceptFromKey;

final class Rfc6455UpgradeHandler implements RequestHandler
{
    public function __construct(
        private readonly ErrorHandler $errorHandler = new DefaultErrorHandler(),
    ) {
    }

    public function handleRequest(Request $request): Response
    {
        if ($request->getMethod() !== 'GET') {
            $response = $this->errorHandler->handleError(Status::METHOD_NOT_ALLOWED, null, $request);
            $response->setHeader('allow', 'GET');
            return $response;
        }

        if ($request->getProtocolVersion() !== '1.1') {
            $response = $this->errorHandler->handleError(Status::HTTP_VERSION_NOT_SUPPORTED, null, $request);
            $response->setHeader('upgrade', 'websocket');
            return $response;
        }

        if ('' !== $request->getBody()->buffer()) {
            return $this->errorHandler->handleError(Status::BAD_REQUEST, null, $request);
        }

        $hasUpgradeWebsocket = false;
        foreach ($request->getHeaderArray('upgrade') as $value) {
            if (\strcasecmp($value, 'websocket') === 0) {
                $hasUpgradeWebsocket = true;
                break;
            }
        }
        if (!$hasUpgradeWebsocket) {
            $response = $this->errorHandler->handleError(Status::UPGRADE_REQUIRED, null, $request);
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
            $response = $this->errorHandler->handleError(Status::UPGRADE_REQUIRED, $reason, $request);
            $response->setHeader('upgrade', 'websocket');
            return $response;
        }

        if (!$acceptKey = $request->getHeader('sec-websocket-key')) {
            $reason = 'Bad Request: "Sec-Websocket-Key" header required';
            return $this->errorHandler->handleError(Status::BAD_REQUEST, $reason, $request);
        }

        if (!\in_array('13', $request->getHeaderArray('sec-websocket-version'), true)) {
            $reason = 'Bad Request: Requested Websocket version unavailable';
            $response = $this->errorHandler->handleError(Status::BAD_REQUEST, $reason, $request);
            $response->setHeader('sec-websocket-version', '13');
            return $response;
        }

        return new Response(Status::SWITCHING_PROTOCOLS, [
            'connection' => 'upgrade',
            'upgrade' => 'websocket',
            'sec-websocket-accept' => generateAcceptFromKey($acceptKey),
        ]);
    }
}
