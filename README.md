# Websocket Server

This library provides a [Request Handler](https://amphp.org/http-server/classes/request-handler) to easily handle Websocket 
connections using [amphp/http-server](https://github.com/amphp/http-server).

## Installation

This package can be installed as a [Composer](https://getcomposer.org) dependency.

```
composer require amphp/websocket-server
```

> Currently this library is undergoing a RC phase on a push to 2.0! Please check out the 2.0 RC and let us know if you have any feedback.

## Documentation

The documentation for this library is currently a work in progress. Pull Requests to improve the documentation 
are always welcome!

## Requirements

- PHP 7.1+

## Examples

### Simple websocket server

```php
<?php

// Note that this example requires:
// amphp/log

use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Log\StreamHandler;
use Amp\Loop;
use Amp\Promise;
use Amp\Socket\Server;
use Amp\Success;
use Amp\Websocket\Client;
use Amp\Websocket\Server\ClientHandler;
use Amp\Websocket\Server\Websocket;
use Monolog\Logger;
use function Amp\ByteStream\getStdout;

require __DIR__ . '/vendor/autoload.php';

$websocket = new Websocket(new class implements ClientHandler {
    /** @var Websocket */
    private $endpoint;

    public function onStart(Websocket $endpoint): Promise
    {
        $this->endpoint = $endpoint;
        return new Success;
    }

    public function onStop(Websocket $endpoint): Promise
    {
        $this->endpoint = null;
        return new Success;
    }

    public function handleHandshake(Request $request, Response $response): Promise
    {
        /*
        if (!\array_key_exists('sec-websocket-key', $request->getHeaders())) {
            $response->setStatus(403);
        }
        */

        return new Success($response);
    }

    public function handleClient(Client $client, Request $request, Response $response): Promise
    {
        return Amp\call(function () use ($client) {
            while ($message = yield $client->receive()) {
                $payload = yield $message->buffer();
                yield $client->send('Message of length ' . \strlen($payload) . 'received');
            }
        });
    }
});

$logHandler = new StreamHandler(getStdout());
$logger = new Logger('server');
$logger->pushHandler($logHandler);

$server = new HttpServer([Server::listen('0.0.0.0:8080')], $websocket, $logger);

Loop::run(function () use ($server) {
    yield $server->start();
});
```
