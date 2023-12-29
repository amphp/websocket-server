# amphp/websocket-server

AMPHP is a collection of event-driven libraries for PHP designed with fibers and concurrency in mind.
This library provides a [`RequestHandler`](https://amphp.org/http-server/classes/request-handler) to easily handle WebSocket connections using [`amphp/http-server`](https://github.com/amphp/http-server).

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

Accepting client connections is performed by an instance of `WebsocketAcceptor`. This library provides two implementations:  and `AllowOriginAcceptor`
- `Rfc6455Acceptor`: Accepts client connections based on [RFC6455](https://datatracker.ietf.org/doc/html/rfc6455) with no further restrictions.
- `AllowOriginAcceptor`: Requires the `"Origin"` header of the HTTP request to match one of the allowed origins provided to the constructor. Accepting the connection is then delegated to another `WebsocketAcceptor` implementation (`Rfc6455Acceptor` by default).

### Handling Client Connections

Once established, a WebSocket connection is handled by an implementation of `WebsocketClientHandler`. This is where your WebSocket application logic will go.

`WebsocketClientHanler` has a single method which must be implemented, `handleClient()`.

```php
public function handleClient(
    WebsocketClient $client,
    Request $request,
    Response $response,
): void;
```

After accepting a client connection, `WebsocketClientHandler::handleClient()` is invoked with the `WebsocketClient` instance, as well as the `Request` and `Response` instances which were used to establish the connection.

This method should not return until the client connection should be closed. This method generally should not throw an exception. Any exception thrown will close the connection with an `UNEXPECTED_SERVER_ERROR` error code (1011) and forward the exception to the HTTP server logger. There is one exception to this: `WebsocketClosedException`, which is thrown when receiving or sending a message to a connection fails due to the connection being closed. If `WebsocketClosedException` is thrown from `handleClient()`, the exception is ignored.

### Gateways

A `WebsocketGateway` provides a means of collecting WebSocket clients into related groups to allow broadcasting a single message efficiently (and asynchronously) to multiple clients. `WebsocketClientGateway` provided by this library may be used by one or more client handlers to group clients from one or more endpoints (or multiple may be used on a single endpoint if desired). See the [example server](#example-server) below for basic usage of a gateway in a client handler. Clients added to the gateway are automatically removed when the client connection is closed.

### Compression

Message compression may optionally be enabled on individual WebSocket endpoints by passing an instance of `WebsocketCompressionContextFactory` to the `Websocket` constructor. Currently, the only implementation available is `Rfc7692CompressionFactory` which implements compression based on [RFC-7692](https://datatracker.ietf.org/doc/html/rfc7692).

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
