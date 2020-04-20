<?php

require \dirname(__DIR__) . "/vendor/autoload.php";

use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Promise;
use Amp\Socket\Server;
use Amp\Success;
use Amp\Websocket\Client;
use Amp\Websocket\Message;
use Amp\Websocket\Options;
use Amp\Websocket\Server\ClientHandler;
use Amp\Websocket\Server\Endpoint;
use Amp\Websocket\Server\Websocket;
use Psr\Log\NullLogger;

Amp\Loop::run(function (): Promise {
    /* --- http://localhost:9001/ ------------------------------------------------------------------- */

    $options = Options::createServerDefault()
        ->withBytesPerSecondLimit(\PHP_INT_MAX)
        ->withFrameSizeLimit(\PHP_INT_MAX)
        ->withFramesPerSecondLimit(\PHP_INT_MAX)
        ->withMessageSizeLimit(\PHP_INT_MAX)
        ->withValidateUtf8(true);

    $websocket = new Websocket(new class implements ClientHandler {
        public function handleHandshake(Endpoint $endpoint, Request $request, Response $response): Promise
        {
            return new Success($response);
        }

        public function handleClient(Endpoint $endpoint, Client $client, Request $request, Response $response): Promise
        {
            return Amp\call(function () use ($endpoint, $client) {
                while ($message = yield $client->receive()) {
                    \assert($message instanceof Message);
                    if ($message->isBinary()) {
                        yield $endpoint->broadcastBinary(yield $message->buffer());
                    } else {
                        yield $endpoint->broadcast(yield $message->buffer());
                    }
                }
            });
        }
    }, $options);

    $server = new HttpServer([Server::listen("127.0.0.1:9001")], $websocket, new NullLogger);
    return $server->start();
});
