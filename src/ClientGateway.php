<?php

namespace Amp\Websocket\Server;

use Amp\CompositeException;
use Amp\Future;
use Amp\Http\Server\Driver\UpgradedSocket;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Websocket\Client;
use Amp\Websocket\ClosedException;
use Amp\Websocket\Code;
use Amp\Websocket\CompressionContext;
use Amp\Websocket\CompressionContextFactory;
use Amp\Websocket\Options;
use Psr\Log\LoggerInterface as PsrLogger;
use Revolt\EventLoop;
use function Amp\async;

final class ClientGateway implements Gateway
{
    private PsrLogger $logger;

    private ErrorHandler $errorHandler;

    /** @var array<int, Client> Indexed by client ID. */
    private array $clients = [];

    /** @var array<int, Internal\AsyncSender> Senders indexed by client ID. */
    private array $senders = [];


    public function __construct(
        private readonly ClientHandler $clientHandler,
        private Options $options,
        private readonly ClientFactory $clientFactory,
        private readonly CompressionContextFactory $compressionFactory,
    ) {
    }

    public function handleHandshake(Request $request, Response $response): Response
    {
        \assert($response->getStatus() === Status::SWITCHING_PROTOCOLS);

        $response = $this->clientHandler->handleHandshake($this, $request, $response);

        if ($response->getStatus() !== Status::SWITCHING_PROTOCOLS) {
            $response->removeHeader('connection');
            $response->removeHeader('upgrade');
            $response->removeHeader('sec-websocket-accept');
            return $response;
        }

        $compressionContext = null;
        if ($this->options->isCompressionEnabled()) {
            $extensions = \array_map('trim', \explode(',', (string) $request->getHeader('sec-websocket-extensions')));

            foreach ($extensions as $extension) {
                if ($compressionContext = $this->compressionFactory->fromClientHeader($extension, $headerLine)) {
                    /** @psalm-suppress PossiblyNullArgument */
                    $response->setHeader('sec-websocket-extensions', $headerLine);
                    break;
                }
            }
        }

        $response->upgrade(fn (UpgradedSocket $socket) => $this->reapClient($socket, $request, $response, $compressionContext));

        return $response;
    }

    private function reapClient(UpgradedSocket $socket, Request $request, Response $response, ?CompressionContext $compressionContext): void
    {
        \assert(isset($this->logger)); // For Psalm.

        $client = $this->clientFactory->createClient($request, $response, $socket, $this->options, $compressionContext);

        $socketResource = $socket->getResource();

        // Setting via stream API doesn't work and TLS streams are not supported
        // once TLS is enabled
        $isNodelayChangeSupported = $socketResource !== null
            && !isset(\stream_get_meta_data($socketResource)["crypto"])
            && \function_exists('socket_import_stream')
            && \defined('TCP_NODELAY');

        if ($isNodelayChangeSupported && ($sock = \socket_import_stream($socketResource))) {
            /** @noinspection PhpComposerExtensionStubsInspection */
            @\socket_set_option($sock, \SOL_TCP, \TCP_NODELAY, 1); // error suppression for sockets which don't support the option
        }

        // @formatter:off
        /** @noinspection SuspiciousBinaryOperationInspection */
        \assert($this->logger->debug(\sprintf(
                'Upgraded %s #%d to websocket connection #%d',
                $socket->getRemoteAddress()->toString(),
                $socket->getClient()->getId(),
                $client->getId()
            )) || true);
        // @formatter:on
        EventLoop::queue(fn () => $this->runClient($client, $request, $response));
    }

    private function runClient(Client $client, Request $request, Response $response): void
    {
        \assert(isset($this->logger)); // For Psalm.

        $id = $client->getId();
        $this->clients[$id] = $client;
        $this->senders[$id] = new Internal\AsyncSender($client);

        $client->onClose(function (Client $client, int $code, string $reason): void {
            $id = $client->getId();
            unset($this->clients[$id], $this->senders[$id]);

            if (!$client->isClosedByPeer()) {
                return;
            }

            switch ($code) {
                case Code::PROTOCOL_ERROR:
                case Code::UNACCEPTABLE_TYPE:
                case Code::POLICY_VIOLATION:
                case Code::INCONSISTENT_FRAME_DATA_TYPE:
                case Code::MESSAGE_TOO_LARGE:
                case Code::EXPECTED_EXTENSION_MISSING:
                case Code::BAD_GATEWAY:
                    \assert(isset($this->logger)); // For Psalm.
                    $this->logger->notice(\sprintf(
                        'Client initiated websocket close reporting error (code: %d): %s',
                        $code,
                        $reason
                    ));
            }
        });

        try {
            $this->clientHandler->handleClient($this, $client, $request, $response);
        } catch (ClosedException $exception) {
            // Ignore ClosedExceptions thrown from closing the client while streaming a message.
        } catch (\Throwable $exception) {
            $this->logger->error((string) $exception);
            $client->close(Code::UNEXPECTED_SERVER_ERROR, 'Internal server error, aborting');
            return;
        }

        if ($client->isConnected()) {
            $client->close(Code::NORMAL_CLOSE, 'Closing connection');
        }
    }

    public function broadcast(string $data, array $exceptIds = []): Future
    {
        return $this->broadcastData($data, false, $exceptIds);
    }

    private function broadcastData(string $data, bool $binary, array $exceptIds = []): Future
    {
        $exceptIdLookup = \array_flip($exceptIds);

        /** @psalm-suppress DocblockTypeContradiction array_flip() can return null. */
        if ($exceptIdLookup === null) {
            throw new \Error('Unable to array_flip() the passed IDs');
        }

        $futures = [];
        foreach ($this->senders as $id => $sender) {
            if (isset($exceptIdLookup[$id])) {
                continue;
            }
            $futures[$id] = $sender->send($data, $binary);
        }

        return async(static fn () => Future\settle($futures));
    }

    public function broadcastBinary(string $data, array $exceptIds = []): Future
    {
        return $this->broadcastData($data, true, $exceptIds);
    }

    public function multicast(string $data, array $clientIds): Future
    {
        return $this->multicastData($data, false, $clientIds);
    }

    private function multicastData(string $data, bool $binary, array $clientIds): Future
    {
        $futures = [];
        foreach ($clientIds as $id) {
            if (!isset($this->senders[$id])) {
                continue;
            }
            $sender = $this->senders[$id];
            $futures[$id] = $sender->send($data, $binary);
        }
        return async(static fn () => Future\settle($futures));
    }

    /**
     * Send a binary message to a set of clients.
     *
     * @param string $data Data to send.
     * @param int[] $clientIds Array of client IDs.
     *
     * @return Future<array> Resolves once the message has been sent to all clients. Note it is
     *                       generally undesirable to await this future in a coroutine.
     */
    public function multicastBinary(string $data, array $clientIds): Future
    {
        return $this->multicastData($data, true, $clientIds);
    }

    /**
     * @return Client[] Array of Client objects currently connected to this endpoint indexed by their IDs.
     */
    public function getClients(): array
    {
        return $this->clients;
    }

    /**
     * @return PsrLogger Server logger.
     */
    public function getLogger(): PsrLogger
    {
        if (!isset($this->logger)) {
            throw new \Error('Cannot get logger until the server has started');
        }

        return $this->logger;
    }

    /**
     * @return ErrorHandler Server error handler.
     */
    public function getErrorHandler(): ErrorHandler
    {
        if (!isset($this->errorHandler)) {
            throw new \Error('Cannot get error handler until the server has started');
        }

        return $this->errorHandler;
    }

    /**
     * @return Options
     */
    public function getOptions(): Options
    {
        return $this->options;
    }

    public function onStart(HttpServer $server): void
    {
        $this->logger = $server->getLogger();
        $this->errorHandler = $server->getErrorHandler();

        if ($this->options->isCompressionEnabled() && !\extension_loaded('zlib')) {
            $this->options = $this->options->withoutCompression();
            $this->logger->warning('Message compression is enabled in websocket options, but ext-zlib is required for compression');
        }

        if (!empty($exceptions)) {
            throw new CompositeException($exceptions, 'Websocket initialization failed');
        }
    }

    public function onStop(HttpServer $server): void
    {
        $closeFutures = [];
        foreach ($this->clients as $client) {
            $closeFutures[] = async(fn () => $client->close(Code::GOING_AWAY, 'Server shutting down!'));
        }

        Future\settle($closeFutures); // Ignore client close failures since we're shutting down anyway.

        if (!empty($exceptions)) {
            throw new CompositeException($exceptions, 'Websocket shutdown failed');
        }
    }
}
