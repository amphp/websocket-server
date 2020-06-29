<?php

// Note that this example requires amphp/http-server-router,
// amphp/http-server-static-content and amphp/log to be installed.

use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Http\Status;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Loop;
use Amp\Promise;
use Amp\Socket\Server as SocketServer;
use Amp\Success;
use Amp\Websocket\Client;
use Amp\Websocket\Message;
use Amp\Websocket\Server\ClientHandler;
use Amp\Websocket\Server\Gateway;
use Amp\Websocket\Server\Websocket;
use Monolog\Logger;
use function Amp\ByteStream\getStdout;

require __DIR__ . '/../../vendor/autoload.php';

Loop::run(function (): Promise {
    $websocket = new Websocket(new class implements ClientHandler {
        public function handleHandshake(Gateway $gateway, Request $request, Response $response): Promise
        {
            if (!\in_array($request->getHeader('origin'), ['http://localhost:1337', 'http://127.0.0.1:1337', 'http://[::1]:1337'], true)) {
                return $gateway->getErrorHandler()->handleError(Status::FORBIDDEN, 'Origin forbidden', $request);
            }

            return new Success($response);
        }

        public function handleClient(Gateway $gateway, Client $client, Request $request, Response $response): Promise
        {
            return Amp\call(function () use ($gateway, $client) {
                while ($message = yield $client->receive()) {
                    \assert($message instanceof Message);
                    $gateway->broadcast(\sprintf('%d: %s', $client->getId(), yield $message->buffer()));
                }
            });
        }
    });

    $sockets = [
        SocketServer::listen('127.0.0.1:1337'),
        SocketServer::listen('[::1]:1337'),
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
