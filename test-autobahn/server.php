<?php

require \dirname(__DIR__) . "/vendor/autoload.php";

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\Server;
use Amp\Promise;
use Amp\Socket;
use Amp\Success;
use Amp\Websocket\Client;
use Amp\Websocket\Message;
use Amp\Websocket\Options;
use Amp\Websocket\Server\Websocket;
use Psr\Log\NullLogger;

Amp\Loop::run(function () {
    /* --- http://localhost:9001/ ------------------------------------------------------------------- */

    $options = Options::createServerDefault()
        ->withBytesPerSecondLimit(\PHP_INT_MAX)
        ->withFrameSizeLimit(\PHP_INT_MAX)
        ->withFramesPerSecondLimit(\PHP_INT_MAX)
        ->withMessageSizeLimit(\PHP_INT_MAX)
        ->withValidateUtf8(true);

    // @formatter:off
    $websocket = new class($options) extends Websocket {
        // @formatter:on
        protected function onHandshake(Request $request, Response $response): Promise
        {
            return new Success($response);
        }

        protected function onConnect(Client $client, Request $request, Response $response): Promise
        {
            return Amp\call(function () use ($client) {
                while ($message = yield $client->receive()) {
                    \assert($message instanceof Message);
                    if ($message->isBinary()) {
                        yield $this->broadcastBinary(yield $message->buffer());
                    } else {
                        yield $this->broadcast(yield $message->buffer());
                    }
                }
            });
        }
    };

    $server = new Server([Socket\listen("127.0.0.1:9001")], $websocket, new NullLogger);
    return $server->start();
});
