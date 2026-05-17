# amphp/websocket-server

AMPHP is a collection of event-driven libraries for PHP designed with fibers and concurrency in mind.
This library provides a [`RequestHandler`](https://amphp.org/http-server#requesthandler) to easily handle WebSocket connections using [`amphp/http-server`](https://github.com/amphp/http-server).

## Requirements

- PHP 8.1+

## Installation

This package can be installed as a [Composer](https://getcomposer.org) dependency.

```
composer require amphp/websocket-server
```

## Documentation

The primary component of this library is the `Websocket` class, an implementation of the `RequestHandler` interface from [`amphp/http-server`](https://github.com/amphp/http-server). Endpoints using the `Websocket` request handler will upgrade incoming requests to a WebSocket connection.

Creating a `Websocket` endpoint requires the user to specify a number of parameters:
- The `Amp\Http\Server\HttpServer` instance which will be used
- A [PSR-3](https://www.php-fig.org/psr/psr-3/) logger instance
- A `WebsocketAcceptor` to accept client connections
- A `WebsocketClientHandler` to handle client connections once accepted
- An optional `WebsocketCompressionContextFactory` if compression should be enabled on the server
- An optional `WebsocketClientFactory` if custom logic is needed when creating `WebsocketClient` instances

### Accepting Client Connections

Accepting client connections is performed by an instance of `WebsocketAcceptor`. This library provides two implementations:
- `Rfc6455Acceptor`: Accepts client connections based on [RFC6455](https://datatracker.ietf.org/doc/html/rfc6455) with no further restrictions.
- `AllowOriginAcceptor`: Requires the `"Origin"` header of the HTTP request to match one of the allowed origins provided to the constructor. Accepting the connection is then delegated to another `WebsocketAcceptor` implementation (`Rfc6455Acceptor` by default).

Both `Rfc6455Acceptor` and `AllowOriginAcceptor` accept an optional `ErrorHandler` as a constructor argument, allowing you to customise the HTTP response returned when a connection is rejected (e.g. to return a JSON error body).

### Handling Client Connections

Once established, a WebSocket connection is handled by an implementation of `WebsocketClientHandler`. Your application logic will be within an implementation of this interface.

`WebsocketClientHandler` has a single method which must be implemented, `handleClient()`.

```php
public function handleClient(
    WebsocketClient $client,
    Request $request,
    Response $response,
): void;
```

After accepting a client connection, `WebsocketClientHandler::handleClient()` is invoked with the `WebsocketClient` instance, as well as the `Request` and `Response` instances which were used to establish the connection.

This method should not return until the client connection should be closed. Exceptions should not be thrown from this method. Any exception thrown will close the connection with an `UNEXPECTED_SERVER_ERROR` error code (1011) and forward the exception to the HTTP server logger. There is one exception to this: `WebsocketClosedException`, which is thrown when receiving or sending a message to a connection fails due to the connection being closed. If `WebsocketClosedException` is thrown from `handleClient()`, the exception is ignored.

### Receiving Messages

Messages are received by iterating over the `WebsocketClient` instance or by calling `receive()` directly. The iterator (and `receive()`) returns `null` and exits cleanly when the connection is closed — no try/catch is needed for the normal close case.

```php
// Iterate over incoming messages until the connection closes
foreach ($client as $message) {
    $payload = $message->buffer();
    $isBinary = $message->isBinary();
    $client->sendText('Echo: ' . $payload);
}
```

`WebsocketClosedException` is only thrown when the connection is closed while a send or receive operation is already in progress mid-message. It bubbles up through `handleClient()` and is silently swallowed by the `Websocket` handler.

### Gateways

A `WebsocketGateway` provides a means of collecting WebSocket clients into related groups to allow broadcasting a single message efficiently (and asynchronously) to multiple clients. `WebsocketClientGateway` provided by this library may be used by one or more client handlers to group clients from one or more endpoints (or multiple may be used on a single endpoint if desired). See the [example server](#example-server) below for basic usage of a gateway in a client handler. Clients added to the gateway are automatically removed when the client connection is closed.

The `WebsocketGateway` interface provides the following methods:

- `broadcastText(string $data, array $excludedClientIds = [])` — send a UTF-8 text message to all connected clients, with an optional exclusion list.
- `broadcastBinary(string $data, array $excludedClientIds = [])` — same as above for binary messages.
- `multicastText(string $data, array $clientIds)` / `multicastBinary(...)` — send to a specific set of client IDs.
- `sendText(string $data, int $clientId)` / `sendBinary(...)` — send to a single client via the gateway's send queue, guaranteeing ordering with any concurrent broadcast or multicast messages.
- `getClients()` — returns an array of all currently connected `WebsocketClient` instances indexed by client ID.

> **Ordering note:** Messages sent with `WebsocketClient::sendText()` / `sendBinary()` directly bypass the gateway's send queue. If you mix direct sends with gateway sends, message order is not guaranteed. Use the gateway's `sendText()`/`sendBinary()` methods when ordering relative to broadcasts matters.

### Compression

Message compression may optionally be enabled on individual WebSocket endpoints by passing an instance of `WebsocketCompressionContextFactory` to the `Websocket` constructor. Currently, the only implementation available is `Rfc7692CompressionFactory` which implements compression based on [RFC-7692](https://datatracker.ietf.org/doc/html/rfc7692).

### Customising the Client Factory

By default, `Websocket` creates clients using `Rfc6455ClientFactory`. You can pass your own `Rfc6455ClientFactory` instance (or a custom `WebsocketClientFactory` implementation) to control low-level client behaviour:

`Rfc6455ClientFactory` constructor parameters:

| Parameter | Default | Description |
|---|---|---|
| `$heartbeatQueue` | `PeriodicHeartbeatQueue` | Sends periodic pings to detect dead connections. Pass `null` to disable. |
| `$rateLimit` | `ConstantRateLimit` | Limits the rate of incoming messages per client. Pass `null` to disable. |
| `$parserFactory` | `Rfc6455ParserFactory` | Factory for the low-level frame parser. |
| `$frameSplitThreshold` | `Rfc6455Client::DEFAULT_FRAME_SPLIT_THRESHOLD` | Large messages are split into frames of at most this many bytes. |
| `$closePeriod` | `Rfc6455Client::DEFAULT_CLOSE_PERIOD` | Seconds to wait for a close frame from the client before forcefully closing the socket. |

### Example Server

The server below creates a simple WebSocket endpoint which broadcasts all received messages to all other connected clients. [`amphp/http-server-router`](https://github.com/amphp/http-server-router) and [`amphp/http-server-static-content`](https://github.com/amphp/http-server-static-content) are used to attach the `Websocket` handler to a specific route and to serve static files from the `/public` directory if the route is not defined in the router.

```php
<?php

// Note that this example requires amphp/http-server-router,
// amphp/http-server-static-content and amphp/log to be installed.

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\SocketHttpServer;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket;
use Amp\Websocket\Server\AllowOriginAcceptor;
use Amp\Websocket\Server\Websocket;
use Amp\Websocket\Server\WebsocketClientGateway;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\Server\WebsocketGateway;
use Amp\Websocket\WebsocketClient;
use Monolog\Logger;
use function Amp\trapSignal;
use function Amp\ByteStream\getStdout;

require __DIR__ . '/../../vendor/autoload.php';

$logHandler = new StreamHandler(getStdout());
$logHandler->setFormatter(new ConsoleFormatter());
$logger = new Logger('server');
$logger->pushHandler($logHandler);

$server = SocketHttpServer::createForDirectAccess($logger);

$server->expose(new Socket\InternetAddress('127.0.0.1', 1337));
$server->expose(new Socket\InternetAddress('[::1]', 1337));

$errorHandler = new DefaultErrorHandler();

$acceptor = new AllowOriginAcceptor(
    ['http://localhost:1337', 'http://127.0.0.1:1337', 'http://[::1]:1337'],
);

$clientHandler = new class implements WebsocketClientHandler {
    public function __construct(
        private readonly WebsocketGateway $gateway = new WebsocketClientGateway(),
    ) {
    }

    public function handleClient(
        WebsocketClient $client,
        Request $request,
        Response $response,
    ): void {
        $this->gateway->addClient($client);

        foreach ($client as $message) {
            $this->gateway->broadcastText(sprintf(
                '%d: %s',
                $client->getId(),
                (string) $message,
            ));
        }
    }
};

$websocket = new Websocket($server, $logger, $acceptor, $clientHandler);

$router = new Router($server, $logger, $errorHandler);
$router->addRoute('GET', '/broadcast', $websocket);
$router->setFallback(new DocumentRoot($server, $errorHandler, __DIR__ . '/public'));

$server->start($router, $errorHandler);

// Await SIGINT or SIGTERM to be received.
$signal = trapSignal([SIGINT, SIGTERM]);

$logger->info(sprintf("Received signal %d, stopping HTTP server", $signal));

$server->stop();
```

## Versioning

`amphp/websocket-server` follows the [semver](http://semver.org/) semantic versioning specification like all other `amphp` packages.

## Security

If you discover any security related issues, please use the private security issue reporter instead of using the public issue tracker.

## License

The MIT License (MIT). Please see [`LICENSE`](./LICENSE) for more information.
