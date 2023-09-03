<?php declare(strict_types=1);

namespace Amp\Websocket\Server;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Http\HttpStatus;
use Amp\Http\Server\Driver\UpgradedSocket;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Websocket\Compression\WebsocketCompressionContext;
use Amp\Websocket\Compression\WebsocketCompressionContextFactory;
use Amp\Websocket\WebsocketClient;
use Amp\Websocket\WebsocketCloseCode;
use Amp\Websocket\WebsocketClosedException;
use Psr\Log\LoggerInterface as PsrLogger;
use Revolt\EventLoop;

final class Websocket implements RequestHandler
{
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * @param WebsocketCompressionContextFactory|null $compressionContextFactory Use null to disable compression.
     */
    public function __construct(
        private readonly PsrLogger $logger,
        private readonly WebsocketHandshakeHandler $handshakeHandler,
        private readonly WebsocketClientHandler $clientHandler,
        private readonly WebsocketClientFactory $clientFactory = new Rfc6455ClientFactory(),
        private readonly RequestHandler $upgradeHandler = new Rfc6455UpgradeHandler(),
        private readonly ?WebsocketCompressionContextFactory $compressionContextFactory = null,
    ) {
    }

    public function handleRequest(Request $request): Response
    {
        $response = $this->upgradeHandler->handleRequest($request);

        if ($response->getStatus() !== HttpStatus::SWITCHING_PROTOCOLS) {
            return $response;
        }

        $response = $this->handshakeHandler->handleHandshake($request, $response);

        if ($response->getStatus() !== HttpStatus::SWITCHING_PROTOCOLS) {
            $response->removeHeader('connection');
            $response->removeHeader('upgrade');
            $response->removeHeader('sec-websocket-accept');
            return $response;
        }

        $compressionContext = null;
        if ($this->compressionContextFactory) {
            $extensions = \array_map('trim', \explode(',', (string) $request->getHeader('sec-websocket-extensions')));

            foreach ($extensions as $extension) {
                if ($compressionContext = $this->compressionContextFactory->fromClientHeader($extension, $headerLine)) {
                    /** @psalm-suppress PossiblyNullArgument */
                    $response->setHeader('sec-websocket-extensions', $headerLine);
                    break;
                }
            }
        }

        $response->upgrade(
            fn (UpgradedSocket $socket) => $this->reapClient($socket, $request, $response, $compressionContext)
        );

        return $response;
    }

    private function reapClient(
        UpgradedSocket $socket,
        Request $request,
        Response $response,
        ?WebsocketCompressionContext $compressionContext
    ): void {
        $client = $this->clientFactory->createClient($request, $response, $socket, $compressionContext);

        $socketResource = $socket->getResource();

        // Setting via stream API doesn't work and TLS streams are not supported
        // once TLS is enabled
        $isNodelayChangeSupported = \is_resource($socketResource)
            && !isset(\stream_get_meta_data($socketResource)["crypto"])
            && \extension_loaded('sockets')
            && \defined('TCP_NODELAY');

        if ($isNodelayChangeSupported && ($sock = \socket_import_stream($socketResource))) {
            \set_error_handler(static fn () => true);
            try {
                // error suppression for sockets which don't support the option
                \socket_set_option($sock, \SOL_TCP, \TCP_NODELAY, 1);
            } finally {
                \restore_error_handler();
            }
        }

        /** @psalm-suppress  RedundantCondition */
        \assert($this->logger->debug(\sprintf(
            'Upgraded %s #%d to websocket connection #%d',
            $socket->getRemoteAddress()->toString(),
            $socket->getClient()->getId(),
            $client->getId(),
        )) || true);

        EventLoop::queue($this->handleClient(...), $client, $request, $response);
    }

    private function handleClient(WebsocketClient $client, Request $request, Response $response): void
    {
        $client->onClose(function (int $clientId, int $closeCode, string $closeReason, bool $closedByPeer): void {
            /** @psalm-suppress  RedundantCondition */
            \assert($this->logger->debug(\sprintf(
                'Closed websocket connection #%d (code: %d) %s',
                $clientId,
                $closeCode,
                $closeReason,
            )) || true);

            if (!$closedByPeer) {
                return;
            }

            switch ($closeCode) {
                case WebsocketCloseCode::PROTOCOL_ERROR:
                case WebsocketCloseCode::UNACCEPTABLE_TYPE:
                case WebsocketCloseCode::POLICY_VIOLATION:
                case WebsocketCloseCode::INCONSISTENT_FRAME_DATA_TYPE:
                case WebsocketCloseCode::MESSAGE_TOO_LARGE:
                case WebsocketCloseCode::EXPECTED_EXTENSION_MISSING:
                case WebsocketCloseCode::BAD_GATEWAY:
                    $this->logger->notice(\sprintf(
                        'Client initiated websocket close reporting error (code: %d) %s',
                        $closeCode,
                        $closeReason,
                    ));
            }
        });

        try {
            $this->clientHandler->handleClient($client, $request, $response);
        } catch (WebsocketClosedException) {
            // Ignore WebsocketClosedException thrown from closing the client while streaming a message.
        } catch (\Throwable $exception) {
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
}
