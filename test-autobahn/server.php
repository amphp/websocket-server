<?php

require \dirname(__DIR__) . "/vendor/autoload.php";

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\Server;
use Amp\Socket;
use Amp\Websocket\Client;
use Amp\Websocket\Message;
use Amp\Websocket\Options;
use Amp\Websocket\Server\Websocket;
use Psr\Log\NullLogger;

Amp\Loop::run(function () {
    /* --- http://localhost:9001/ ------------------------------------------------------------------- */

    $options = (new Options)
        ->withBytesPerSecondLimit(\PHP_INT_MAX)
        ->withFrameSizeLimit(\PHP_INT_MAX)
        ->withFramesPerSecondLimit(\PHP_INT_MAX)
        ->withMessageSizeLimit(\PHP_INT_MAX)
        ->withValidateUtf8(true);

    // @formatter:off
    $websocket = new class($options) extends Websocket {
        // @formatter:on
        public function onHandshake(Request $request, Response $response): Response
        {
            return $response;
        }

        public function onConnection(Client $client, Request $request): \Generator
        {
            while ($message = yield $client->receive()) {
                \assert($message instanceof Message);
                if ($message->isBinary()) {
                    yield $this->broadcastBinary(yield $message->buffer());
                } else {
                    yield $this->broadcast(yield $message->buffer());
                }
            }
        }
    };

    $server = new Server([Socket\listen("127.0.0.1:9001")], $websocket, new NullLogger);
    return $server->start();
});
