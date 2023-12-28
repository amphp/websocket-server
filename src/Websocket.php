<?php declare(strict_types=1);

namespace Amp\Websocket\Server;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Future;
use Amp\Http;
use Amp\Http\HttpStatus;
use Amp\Http\Server\Driver\UpgradedSocket;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Websocket\Compression\Rfc7692CompressionFactory;
use Amp\Websocket\Compression\WebsocketCompressionContext;
use Amp\Websocket\Compression\WebsocketCompressionContextFactory;
use Amp\Websocket\WebsocketClient;
use Amp\Websocket\WebsocketCloseCode;
use Amp\Websocket\WebsocketClosedException;
use Amp\Websocket\WebsocketCloseInfo;
use Psr\Log\LoggerInterface as PsrLogger;
use Revolt\EventLoop;
use function Amp\async;

final class Websocket implements RequestHandler
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var \WeakMap<WebsocketClient, true> */
    private \WeakMap $clients;

    /**
     * @param WebsocketCompressionContextFactory|null $compressionFactory Use {@see Rfc7692CompressionFactory} (or your
     *      own implementation) to enable compression or use `null` (default) to disable compression.
     */
    public function __construct(
        HttpServer $httpServer,
        private readonly PsrLogger $logger,
        private readonly WebsocketAcceptor $acceptor,
        private readonly WebsocketClientHandler $clientHandler,
        private readonly ?WebsocketCompressionContextFactory $compressionFactory = null,
        private readonly WebsocketClientFactory $clientFactory = new Rfc6455ClientFactory(),
    ) {
        /** @psalm-suppress PropertyTypeCoercion */
        $this->clients = new \WeakMap();

        $httpServer->onStop($this->onStop(...));
    }

    public function handleRequest(Request $request): Response
    {
        $response = $this->acceptor->handleHandshake($request);

        if ($response->getStatus() !== HttpStatus::SWITCHING_PROTOCOLS) {
            $response->removeHeader('sec-websocket-accept');
            $response->setHeader('connection', 'close');

            return $response;
        }

        $compressionContext = $this->negotiateCompression($request, $response);

        $response->upgrade(fn (UpgradedSocket $socket) => $this->reapClient(
            socket: $socket,
            request: $request,
            response: $response,
            compressionContext: $compressionContext,
        ));

        return $response;
    }

    private function negotiateCompression(Request $request, Response $response): ?WebsocketCompressionContext
    {
        if (!$this->compressionFactory) {
            return null;
        }

        $extensions = Http\splitHeader($request, 'sec-websocket-extensions') ?? [];
        foreach ($extensions as $extension) {
            if ($compressionContext = $this->compressionFactory->fromClientHeader($extension, $headerLine)) {
                \assert(\is_string($headerLine), 'Compression context returned without header line');

                $existingHeader = $response->getHeader('sec-websocket-extensions');
                if ($existingHeader) {
                    $headerLine = $existingHeader . ', ' . $headerLine;
                }

                $response->setHeader('sec-websocket-extensions', $headerLine);

                return $compressionContext;
            }
        }

        return null;
    }

    private function reapClient(
        UpgradedSocket $socket,
        Request $request,
        Response $response,
        ?WebsocketCompressionContext $compressionContext,
    ): void {
        $client = $this->clientFactory->createClient($request, $response, $socket, $compressionContext);

        /** @psalm-suppress RedundantCondition */
        \assert($this->logger->debug(\sprintf(
            'Upgraded %s #%d to websocket connection #%d',
            $socket->getRemoteAddress()->toString(),
            $socket->getClient()->getId(),
            $client->getId(),
        )) || true);

        $this->clients[$client] = true;

        EventLoop::queue($this->handleClient(...), $client, $request, $response);
    }

    private function handleClient(WebsocketClient $client, Request $request, Response $response): void
    {
        $client->onClose(function (int $clientId, WebsocketCloseInfo $closeInfo): void {
            /** @psalm-suppress RedundantCondition */
            \assert($this->logger->debug(\sprintf(
                'Closed websocket connection #%d (code: %d) %s',
                $clientId,
                $closeInfo->getCode(),
                $closeInfo->getReason(),
            )) || true);

            if (!$closeInfo->isByPeer()) {
                return;
            }

            switch ($closeInfo->getCode()) {
                case WebsocketCloseCode::PROTOCOL_ERROR:
                case WebsocketCloseCode::UNACCEPTABLE_TYPE:
                case WebsocketCloseCode::POLICY_VIOLATION:
                case WebsocketCloseCode::INCONSISTENT_FRAME_DATA_TYPE:
                case WebsocketCloseCode::MESSAGE_TOO_LARGE:
                case WebsocketCloseCode::EXPECTED_EXTENSION_MISSING:
                case WebsocketCloseCode::BAD_GATEWAY:
                    $this->logger->notice(\sprintf(
                        'Client initiated websocket close reporting error (code: %d) %s',
                        $closeInfo->getCode(),
                        $closeInfo->getReason(),
                    ));
            }
        });

        try {
            $this->clientHandler->handleClient($client, $request, $response);
        } catch (\Throwable $exception) {
            if ($exception instanceof WebsocketClosedException && $client->isClosed()) {
                // Ignore WebsocketClosedException thrown from closing the client while streaming a message.
                return;
            }

            $this->logger->error(
                \sprintf(
                    "Unexpected %s thrown from %s::handleClient(), closing websocket connection from %s.",
                    $exception::class,
                    $this->clientHandler::class,
                    $client->getRemoteAddress()->toString(),
                ),
                ['exception' => $exception],
            );

            $client->close(WebsocketCloseCode::UNEXPECTED_SERVER_ERROR, 'Internal server error, aborting');
            return;
        }

        if (!$client->isClosed()) {
            $client->close(WebsocketCloseCode::NORMAL_CLOSE, 'Closing connection');
        }
    }

    private function onStop(): void
    {
        $futures = [];
        foreach ($this->clients as $client => $unused) {
            $futures[] = async($client->close(...), WebsocketCloseCode::GOING_AWAY, 'Server shutting down');
        }

        /** @psalm-suppress PropertyTypeCoercion */
        $this->clients = new \WeakMap();

        Future\awaitAll($futures);
    }
}
