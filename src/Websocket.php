<?php

namespace Amp\Websocket\Server;

use Amp\Coroutine;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Server;
use Amp\Http\Server\ServerObserver;
use Amp\Http\Status;
use Amp\Promise;
use Amp\Socket\Socket;
use Amp\Success;
use Amp\Websocket\Client;
use Amp\Websocket\ClosedException;
use Amp\Websocket\Code;
use Amp\Websocket\CompressionContext;
use Amp\Websocket\CompressionContextFactory;
use Amp\Websocket\Options;
use Amp\Websocket\Rfc7692CompressionFactory;
use Psr\Log\LoggerInterface as PsrLogger;
use function Amp\call;
use function Amp\Websocket\generateAcceptFromKey;

abstract class Websocket implements RequestHandler, ServerObserver
{
    /** @var PsrLogger */
    private $logger;

    /** @var Options */
    private $options;

    /** @var ErrorHandler */
    private $errorHandler;

    /** @var CompressionContextFactory */
    private $compressionFactory;

    /** @var ClientFactory */
    private $clientFactory;

    /** @var Client[] */
    private $clients = [];

    /** @var \Closure|null */
    private $onHandshake;

    /** @var \Closure|null */
    private $onConnect;

    /**
     * @param Options|null                   $options
     * @param CompressionContextFactory|null $compressionFactory
     * @param ClientFactory|null             $clientFactory
     */
    public function __construct(
        ?Options $options = null,
        ?CompressionContextFactory $compressionFactory = null,
        ?ClientFactory $clientFactory = null
    ) {
        $this->options = $options ?? new Options;
        $this->compressionFactory = $compressionFactory ?? new Rfc7692CompressionFactory;
        $this->clientFactory = $clientFactory ?? new Rfc6455ClientFactory;
    }

    /**
     * Respond to websocket handshake requests.
     * If a websocket application doesn't wish to impose any special constraints on the
     * handshake it doesn't have to do anything in this method and all handshakes will
     * be automatically accepted.
     * Return an instance of \Amp\Http\Server\Response to reject the websocket connection request.
     *
     * @param Request  $request The HTTP request that instigated the handshake
     * @param Response $response The switching protocol response for adding headers, etc.
     *
     * @return Response|Promise|\Generator Return the given response to accept the
     *     connection or a new response object to deny the connection. May also return a
     *     promise or generator to run as a coroutine.
     */
    abstract protected function onHandshake(Request $request, Response $response);

    /**
     * This method should handle messages received on the websocket connection. The connection is automatically
     * closed when the promise (or coroutine) is resolved.
     *
     * ```
     * while ($message = yield $client->receive()) {
     *     $payload = yield $message->buffer();
     *     yield $client->send('Message of length ' . \strlen($payload) . 'received');
     * }
     * ```
     *
     * @param Client  $client The websocket client connection.
     * @param Request $request The HTTP request that instigated the connection.
     *
     * @return Promise|\Generator Generators returned from this method are run as coroutines.
     */
    abstract protected function onConnect(Client $client, Request $request);

    final public function handleRequest(Request $request): Promise
    {
        if ($this->options === null) {
            throw new \Error(\sprintf(
                "Can't handle WebSocket handshake, because %s::__construct() overrides %s::__construct() and didn't call its parent method.",
                \str_replace("\0", '@', \get_class($this)), // replace NUL-byte in anonymous class name
                self::class
            ));
        }

        if ($this->logger === null) {
            throw new \Error(\sprintf(
                "Can't handle WebSocket handshake, because %s::onStart() overrides %s::onStart() and didn't call its parent method.",
                \str_replace("\0", '@', \get_class($this)), // replace NUL-byte in anonymous class name
                self::class
            ));
        }

        return new Coroutine($this->respond($request));
    }

    private function respond(Request $request): \Generator
    {
        /** @var \Amp\Http\Server\Response $response */
        if ($request->getMethod() !== 'GET') {
            $response = yield $this->errorHandler->handleError(Status::METHOD_NOT_ALLOWED, null, $request);
            $response->setHeader('allow', 'GET');
            return $response;
        }

        if ($request->getProtocolVersion() !== '1.1') {
            $response = yield $this->errorHandler->handleError(Status::HTTP_VERSION_NOT_SUPPORTED, null, $request);
            $response->setHeader('upgrade', 'websocket');
            return $response;
        }

        if ('' !== yield $request->getBody()->buffer()) {
            return yield $this->errorHandler->handleError(Status::BAD_REQUEST, null, $request);
        }

        $hasUpgradeWebsocket = false;
        foreach ($request->getHeaderArray('upgrade') as $value) {
            if (\strcasecmp($value, 'websocket') === 0) {
                $hasUpgradeWebsocket = true;
                break;
            }
        }
        if (!$hasUpgradeWebsocket) {
            return yield $this->errorHandler->handleError(Status::UPGRADE_REQUIRED, null, $request);
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
            $response = yield $this->errorHandler->handleError(Status::UPGRADE_REQUIRED, $reason, $request);
            $response->setHeader('upgrade', 'websocket');
            return $response;
        }

        if (!$acceptKey = $request->getHeader('sec-websocket-key')) {
            $reason = 'Bad Request: "Sec-Websocket-Key" header required';
            return yield $this->errorHandler->handleError(Status::BAD_REQUEST, $reason, $request);
        }

        if (!\in_array('13', $request->getHeaderArray('sec-websocket-version'), true)) {
            $reason = 'Bad Request: Requested Websocket version unavailable';
            $response = yield $this->errorHandler->handleError(Status::BAD_REQUEST, $reason, $request);
            $response->setHeader('sec-websocket-version', '13');
            return $response;
        }

        $response = yield call($this->onHandshake, $request, new Response(Status::SWITCHING_PROTOCOLS));

        if (!$response instanceof Response) {
            throw new \Error(\sprintf(
                '%s::onHandshake() must return or resolve to an instance of %s, %s returned',
                \str_replace("\0", '@', \get_class($this)), // replace NUL-byte in anonymous class name
                Response::class,
                \is_object($response) ? 'instance of ' . \get_class($response) : \gettype($response)
            ));
        }

        if ($response->getStatus() !== Status::SWITCHING_PROTOCOLS) {
            return $response;
        }

        $response->setHeader('connection', 'upgrade');
        $response->setHeader('upgrade', 'websocket');
        $response->setHeader('sec-websocket-accept', generateAcceptFromKey($acceptKey));

        $compressionContext = null;
        if ($this->options->isCompressionEnabled()) {
            $extensions = \array_map('trim', \explode(',', $request->getHeader('sec-websocket-extensions')));

            foreach ($extensions as $extension) {
                if ($compressionContext = $this->compressionFactory->fromClientHeader($extension, $headerLine)) {
                    $response->setHeader('sec-websocket-extensions', $headerLine);
                    break;
                }
            }
        }

        $response->upgrade(function (Socket $socket) use ($request, $compressionContext): void {
            $this->reapClient($socket, $request, $compressionContext);
        });

        return $response;
    }

    private function reapClient(Socket $socket, Request $request, ?CompressionContext $compressionContext): void
    {
        $client = $this->clientFactory->createClient($request, $socket, $this->options, $compressionContext);

        // Setting via stream API doesn't seem to work...
        if (\function_exists('socket_import_stream') && \defined('TCP_NODELAY')) {
            $sock = \socket_import_stream($socket->getResource());
            /** @noinspection PhpComposerExtensionStubsInspection */
            @\socket_set_option($sock, \SOL_TCP, \TCP_NODELAY, 1); // error suppression for sockets which don't support the option
        }

        // @formatter:off
        /** @noinspection SuspiciousBinaryOperationInspection */
        \assert($this->logger->debug(\sprintf(
            'Upgraded %s #%d to websocket connection',
            $client->getRemoteAddress(),
            $client->getId()
        )) || true);
        // @formatter:on

        Promise\rethrow(new Coroutine($this->runClient($client, $request)));
    }

    private function runClient(Client $client, Request $request): \Generator
    {
        $id = $client->getId();
        $this->clients[$id] = $client;

        $client->onClose(function (Client $client, int $code, string $reason): void {
            $id = $client->getId();
            unset($this->clients[$id]);

            if (!$client->didPeerInitiateClose()) {
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
                    $this->logger->notice(\sprintf(
                        'Client initiated websocket close reporting error (code: %d): %s',
                        $code,
                        $reason
                    ));
            }
        });

        try {
            yield call($this->onConnect, $client, $request);
        } catch (ClosedException $exception) {
            // Ignore ClosedExceptions thrown from closing the client while streaming a message.
        } catch (\Throwable $exception) {
            $this->logger->error((string) $exception);
            $client->close(Code::UNEXPECTED_SERVER_ERROR, 'Internal server error, aborting');
        }
    }

    /**
     * Broadcast a UTF-8 text message to all clients (except those given in the optional array).
     *
     * @param string $data Data to send.
     * @param int[]  $exceptIds List of IDs to exclude from the broadcast.
     *
     * @return \Amp\Promise<int>
     */
    final public function broadcast(string $data, array $exceptIds = []): Promise
    {
        return $this->broadcastData($data, false, $exceptIds);
    }

    private function broadcastData(string $data, bool $binary, array $exceptIds = []): Promise
    {
        $promises = [];
        if (empty($exceptIds)) {
            foreach ($this->clients as $id => $client) {
                $promises[] = $binary ? $client->sendBinary($data) : $client->send($data);
            }
        } else {
            $exceptIdLookup = \array_flip($exceptIds);

            if ($exceptIdLookup === null) {
                throw new \Error('Unable to array_flip() the passed IDs');
            }

            foreach ($this->clients as $id => $client) {
                if (isset($exceptIdLookup[$id])) {
                    continue;
                }
                $promises[] = $binary ? $client->sendBinary($data) : $client->send($data);
            }
        }
        return Promise\any($promises);
    }

    /**
     * Send a binary message to all clients (except those given in the optional array).
     *
     * @param string $data Data to send.
     * @param int[]  $exceptIds List of IDs to exclude from the broadcast.
     *
     * @return \Amp\Promise<int>
     */
    final public function broadcastBinary(string $data, array $exceptIds = []): Promise
    {
        return $this->broadcastData($data, true, $exceptIds);
    }

    /**
     * Send a UTF-8 text message to a set of clients.
     *
     * @param string     $data Data to send.
     * @param int[]|null $clientIds Array of client IDs.
     *
     * @return \Amp\Promise<int>
     */
    final public function multicast(string $data, array $clientIds): Promise
    {
        return $this->multicastData($data, false, $clientIds);
    }

    private function multicastData(string $data, bool $binary, array $clientIds): Promise
    {
        $promises = [];
        foreach ($clientIds as $id) {
            if (!isset($this->clients[$id])) {
                continue;
            }
            $client = $this->clients[$id];
            $promises[] = $binary ? $client->sendBinary($data) : $client->send($data);
        }
        return Promise\any($promises);
    }

    /**
     * Send a binary message to a set of clients.
     *
     * @param string     $data Data to send.
     * @param int[]|null $clientIds Array of client IDs.
     *
     * @return \Amp\Promise<int>
     */
    final public function multicastBinary(string $data, array $clientIds): Promise
    {
        return $this->multicastData($data, true, $clientIds);
    }

    /**
     * @return Client[] Array of Client objects currently connected to this endpoint indexed by their IDs.
     */
    final public function getClients(): array
    {
        return $this->clients;
    }

    /**
     * Invoked when the server is starting.
     * Server sockets have been opened, but are not yet accepting client connections. This method should be used to set
     * up any necessary state for responding to requests, including starting loop watchers such as timers.
     * Note: Implementations overriding this method must always call the parent method.
     *
     * @param Server $server
     *
     * @return Promise
     */
    public function onStart(Server $server): Promise
    {
        $this->logger = $server->getLogger();
        $this->errorHandler = $server->getErrorHandler();

        if ($this->options->isCompressionEnabled() && !\extension_loaded('zlib')) {
            $this->options = $this->options->withoutCompression();
            $this->logger->warning('Message compression is enabled in websocket options, but ext-zlib is required for compression');
        }

        $this->onHandshake = \Closure::fromCallable([$this, 'onHandshake']);
        $this->onConnect = \Closure::fromCallable([$this, 'onConnect']);

        return new Success;
    }

    /**
     * Invoked when the server has initiated stopping.
     * No further requests are accepted and any connected clients should be closed gracefully and any loop watchers
     * cancelled.
     * Note: Implementations overriding this method must always call the parent method.
     *
     * @param Server $server
     *
     * @return Promise
     */
    public function onStop(Server $server): Promise
    {
        $code = Code::GOING_AWAY;
        $reason = 'Server shutting down!';

        $promises = [];
        foreach ($this->clients as $client) {
            $promises[] = $client->close($code, $reason);
        }

        $this->onHandshake = $this->onConnect = null;

        return Promise\any($promises);
    }
}
