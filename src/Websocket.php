<?php

namespace Amp\Websocket\Server;

use Amp\Http\Server\Driver\UpgradedSocket;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Websocket\CloseCode;
use Amp\Websocket\ClosedException;
use Amp\Websocket\Compression\CompressionContext;
use Amp\Websocket\Compression\CompressionContextFactory;
use Amp\Websocket\WebsocketClient;
use Amp\Websocket\WebsocketClientMetadata;
use Psr\Log\LoggerInterface as PsrLogger;
use Revolt\EventLoop;

final class Websocket implements RequestHandler
{
    /**
     * @param CompressionContextFactory|null $compressionContextFactory Use null to disable compression.
     */
    public function __construct(
        private readonly PsrLogger $logger,
        private readonly WebsocketHandshakeHandler $handshakeHandler,
        private readonly WebsocketClientHandler $clientHandler,
        private readonly WebsocketClientFactory $clientFactory = new Rfc6455ClientFactory(),
        private readonly RequestHandler $upgradeHandler = new Rfc6455UpgradeHandler(),
        private readonly ?CompressionContextFactory $compressionContextFactory = null,
    ) {
    }

    public function handleRequest(Request $request): Response
    {
        $response = $this->upgradeHandler->handleRequest($request);

        if ($response->getStatus() !== Status::SWITCHING_PROTOCOLS) {
            return $response;
        }

        $response = $this->handshakeHandler->handleHandshake($request, $response);

        if ($response->getStatus() !== Status::SWITCHING_PROTOCOLS) {
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
        ?CompressionContext $compressionContext
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
        $client->onClose(function (WebsocketClientMetadata $metadata): void {
            /** @psalm-suppress  RedundantCondition */
            \assert($this->logger->debug(\sprintf(
                'Closed websocket connection #%d (code: %d) %s',
                $metadata->id,
                $metadata->closeCode ?? 0,
                $metadata->closeReason ?? '',
            )) || true);

            if (!$metadata->closedByPeer) {
                return;
            }

            switch ($metadata->closeCode) {
                case CloseCode::PROTOCOL_ERROR:
                case CloseCode::UNACCEPTABLE_TYPE:
                case CloseCode::POLICY_VIOLATION:
                case CloseCode::INCONSISTENT_FRAME_DATA_TYPE:
                case CloseCode::MESSAGE_TOO_LARGE:
                case CloseCode::EXPECTED_EXTENSION_MISSING:
                case CloseCode::BAD_GATEWAY:
                    $this->logger->notice(\sprintf(
                        'Client initiated websocket close reporting error (code: %d) %s',
                        $metadata->closeCode,
                        $metadata->closeReason ?? '',
                    ));
            }
        });

        try {
            $this->clientHandler->handleClient($client, $request, $response);
        } catch (ClosedException) {
            // Ignore ClosedExceptions thrown from closing the client while streaming a message.
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

            $client->close(CloseCode::UNEXPECTED_SERVER_ERROR, 'Internal server error, aborting');
            return;
        }

        if (!$client->isClosed()) {
            $client->close(CloseCode::NORMAL_CLOSE, 'Closing connection');
        }
    }
}
