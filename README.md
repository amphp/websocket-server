# Websocket Server

This library provides a [`RequestHandler`](https://amphp.org/http-server/classes/request-handler) to easily handle
Websocket connections using [`amphp/http-server`](https://github.com/amphp/http-server).

## Installation

This package can be installed as a [Composer](https://getcomposer.org) dependency.

```
composer require amphp/websocket-server
```

## Documentation

The documentation for this library is currently a work in progress. Pull requests to improve the documentation are
always welcome!

## Requirements

- PHP 7.2+

## Example

```php
<?php

// Note that this example requires:
// amphp/http-server-router
// amphp/http-server-static-content
// amphp/log

use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Loop;
use Amp\Promise;
use Amp\Socket\Server;
use Amp\Success;
use Amp\Websocket\Client;
use Amp\Websocket\Message;
use Amp\Websocket\Server\ClientHandler;
use Amp\Websocket\Server\Gateway;
use Amp\Websocket\Server\Websocket;
use Monolog\Logger;
use function Amp\ByteStream\getStdout;
use function Amp\call;

require __DIR__ . '/vendor/autoload.php';

$websocket = new Websocket(new class implements ClientHandler {
    private const ALLOWED_ORIGINS = [
        'http://localhost:1337',
        'http://127.0.0.1:1337',
        'http://[::1]:1337'
    ];
    
    public function handleHandshake(Gateway $gateway, Request $request, Response $response): Promise
    {
        if (!\in_array($request->getHeader('origin'), self::ALLOWED_ORIGINS, true)) {
            return $gateway->getErrorHandler()->handleError(403);
        }

        return new Success($response);
    }

    public function handleClient(Gateway $gateway, Client $client, Request $request, Response $response): Promise
    {
        return call(function () use ($gateway, $client): \Generator {
            while ($message = yield $client->receive()) {
                \assert($message instanceof Message);
                $gateway->broadcast(\sprintf(
                    '%d: %s',
                    $client->getId(),
                    yield $message->buffer()
                ));
            }
        });
    }
});

Loop::run(function () use ($websocket): Promise {
    $sockets = [
        Server::listen('127.0.0.1:1337'),
        Server::listen('[::1]:1337'),
    ];

    $router = new Router;
    $router->addRoute('GET', '/broadcast', $websocket);
    $router->setFallback(new DocumentRoot(__DIR__ . '/public'));

    $logHandler = new StreamHandler(getStdout());
    $logHandler->setFormatter(new ConsoleFormatter);
    $logger = new Logger('server');
    $logger->pushHandler($logHandler);

    $server = new HttpServer($sockets, $router, $logger);

    return $server->start();
});
```
