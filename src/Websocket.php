<?php

namespace Amp\Websocket\Server;

use Amp\CompositeException;
use Amp\Future;
use Amp\Http\Server\Driver\UpgradedSocket;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\ServerObserver;
use Amp\Http\Status;
use Amp\Websocket\Client;
use Amp\Websocket\ClosedException;
use Amp\Websocket\Code;
use Amp\Websocket\CompressionContext;
use Amp\Websocket\CompressionContextFactory;
use Amp\Websocket\Options;
use Amp\Websocket\Rfc7692CompressionFactory;
use Psr\Log\LoggerInterface as PsrLogger;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\Websocket\generateAcceptFromKey;

final class Websocket implements Gateway, RequestHandler, ServerObserver
{
    private ClientHandler $clientHandler;

    private \SplObjectStorage $observers;

    private PsrLogger $logger;

    private Options $options;

    private ErrorHandler $errorHandler;

    private CompressionContextFactory $compressionFactory;

    private ClientFactory $clientFactory;

    /** @var Client[] Indexed by client ID. */
    private array $clients = [];

    /**
     * @param ClientHandler                  $clientHandler
     * @param Options|null                   $options
     * @param CompressionContextFactory|null $compressionFactory
     * @param ClientFactory|null             $clientFactory
     */
    public function __construct(
        ClientHandler $clientHandler,
        ?Options $options = null,
        ?CompressionContextFactory $compressionFactory = null,
        ?ClientFactory $clientFactory = null
    ) {
        $this->observers = new \SplObjectStorage;

        $this->clientHandler = $clientHandler;
        $this->options = $options ?? Options::createServerDefault();
        $this->compressionFactory = $compressionFactory ?? new Rfc7692CompressionFactory;
        $this->clientFactory = $clientFactory ?? new Rfc6455ClientFactory;

        if ($this->clientHandler instanceof WebsocketServerObserver) {
            $this->observers->attach($this->clientHandler);
        }

        if ($this->clientFactory instanceof WebsocketServerObserver) {
            $this->observers->attach($this->clientFactory);
        }

        if ($this->compressionFactory instanceof WebsocketServerObserver) {
            $this->observers->attach($this->compressionFactory);
        }
    }

    public function handleRequest(Request $request): Response
    {
        \assert(isset($this->logger) && isset($this->errorHandler), \sprintf(
            "Can't handle WebSocket handshake because %s::onStart() was not called by the server",
            self::class
        ));

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

        $response = new Response(Status::SWITCHING_PROTOCOLS, [
            'connection' => 'upgrade',
            'upgrade' => 'websocket',
            'sec-websocket-accept' => generateAcceptFromKey($acceptKey),
        ]);

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

        $response->upgrade(fn(UpgradedSocket $socket) => $this->reapClient($socket, $request, $response, $compressionContext));

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
            && $socket->getTlsInfo() === null
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
            (int) $socketResource,
            $client->getId()
        )) || true);
        // @formatter:on

        EventLoop::queue(fn() => $this->runClient($client, $request, $response));
    }

    private function runClient(Client $client, Request $request, Response $response): void
    {
        \assert(isset($this->logger)); // For Psalm.

        $id = $client->getId();
        $this->clients[$id] = $client;

        $client->onClose(function (Client $client, int $code, string $reason): void {
            $id = $client->getId();
            unset($this->clients[$id]);

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

    /**
     * Broadcast a UTF-8 text message to all clients (except those given in the optional array).
     *
     * @param string $data Data to send.
     * @param int[]  $exceptIds List of IDs to exclude from the broadcast.
     *
     * @return Future<array> Resolves once the message has been sent to all clients. Note it is
     *                        generally undesirable to await this future in a coroutine.
     */
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
        foreach ($this->clients as $id => $client) {
            if (isset($exceptIdLookup[$id])) {
                continue;
            }
            $futures[] = $binary ? $client->sendBinary($data) : $client->send($data);
        }

        return async(static fn () => Future\settle($futures));
    }

    /**
     * Send a binary message to all clients (except those given in the optional array).
     *
     * @param string $data Data to send.
     * @param int[]  $exceptIds List of IDs to exclude from the broadcast.
     *
     * @return Future<array> Resolves once the message has been sent to all clients. Note it is
     *                       generally undesirable to await this future in a coroutine.
     */
    public function broadcastBinary(string $data, array $exceptIds = []): Future
    {
        return $this->broadcastData($data, true, $exceptIds);
    }

    /**
     * Send a UTF-8 text message to a set of clients.
     *
     * @param string $data Data to send.
     * @param int[]  $clientIds Array of client IDs.
     *
     * @return Future<array> Resolves once the message has been sent to all clients. Note it is
     *                       generally undesirable to await this future in a coroutine.
     */
    public function multicast(string $data, array $clientIds): Future
    {
        return $this->multicastData($data, false, $clientIds);
    }

    private function multicastData(string $data, bool $binary, array $clientIds): Future
    {
        $futures = [];
        foreach ($clientIds as $id) {
            if (!isset($this->clients[$id])) {
                continue;
            }
            $client = $this->clients[$id];
            $futures[] = $binary ? $client->sendBinary($data) : $client->send($data);
        }
        return async(static fn () => Future\settle($futures));
    }

    /**
     * Send a binary message to a set of clients.
     *
     * @param string $data Data to send.
     * @param int[]  $clientIds Array of client IDs.
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

    /**
     * Attaches a WebsocketObserver that is notified when the server starts and stops.
     *
     * @param WebsocketServerObserver $observer
     */
    public function attach(WebsocketServerObserver $observer): void
    {
        $this->observers->attach($observer);
    }

    /**
     * @inheritDoc
     */
    public function onStart(HttpServer $server): void
    {
        $this->logger = $server->getLogger();
        $this->errorHandler = $server->getErrorHandler();

        if ($this->options->isCompressionEnabled() && !\extension_loaded('zlib')) {
            $this->options = $this->options->withoutCompression();
            $this->logger->warning('Message compression is enabled in websocket options, but ext-zlib is required for compression');
        }

        $onStartFutures = [];
        foreach ($this->observers as $observer) {
            \assert($observer instanceof WebsocketServerObserver);
            $onStartFutures[] = async(fn() => $observer->onStart($server, $this));
        }

        [$exceptions] = Future\settle($onStartFutures);

        if (!empty($exceptions)) {
            throw new CompositeException($exceptions, 'Websocket initialization failed');
        }
    }

    /**
     * @inheritDoc
     */
    public function onStop(HttpServer $server): void
    {
        $onStopFutures = [];
        foreach ($this->observers as $observer) {
            \assert($observer instanceof WebsocketServerObserver);
            $onStopFutures[] = async(fn() => $observer->onStop($server, $this));
        }

        [$exceptions] = Future\settle($onStopFutures);

        $closeFutures = [];
        foreach ($this->clients as $client) {
            $closeFutures[] = async(fn() => $client->close(Code::GOING_AWAY, 'Server shutting down!'));
        }

        Future\settle($closeFutures); // Ignore client close failures since we're shutting down anyway.

        if (!empty($exceptions)) {
            throw new CompositeException($exceptions, 'Websocket shutdown failed');
        }
    }
}
