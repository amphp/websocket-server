<?php

require \dirname(__DIR__) . "/vendor/autoload.php";

use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Socket;
use Amp\Websocket\Client;
use Amp\Websocket\Message;
use Amp\Websocket\Options;
use Amp\Websocket\Server\ClientHandler;
use Amp\Websocket\Server\Gateway;
use Amp\Websocket\Server\Websocket;
use Psr\Log\NullLogger;

/* --- http://localhost:9001/ ------------------------------------------------------------------- */

$options = Options::createServerDefault()
    ->withBytesPerSecondLimit(\PHP_INT_MAX)
    ->withFrameSizeLimit(\PHP_INT_MAX)
    ->withFramesPerSecondLimit(\PHP_INT_MAX)
    ->withMessageSizeLimit(\PHP_INT_MAX)
    ->withValidateUtf8(true);

$websocket = new Websocket(new class implements ClientHandler {
    public function handleHandshake(Gateway $gateway, Request $request, Response $response): Response
    {
        return $response;
    }

    public function handleClient(Gateway $gateway, Client $client, Request $request, Response $response): void
    {
        while ($message = $client->receive()) {
            \assert($message instanceof Message);
            if ($message->isBinary()) {
                $gateway->broadcastBinary($message->buffer())->await();
            } else {
                $gateway->broadcast($message->buffer())->await();
            }
        }
    }
}, $options);

$server = new HttpServer([Socket\listen("127.0.0.1:9001")], $websocket, new NullLogger);

$server->start();

$signal = Amp\trapSignal([\SIGINT, \SIGTERM, \SIGSTOP]);

$server->stop();
