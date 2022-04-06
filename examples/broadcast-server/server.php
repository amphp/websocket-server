<?php

// Note that this example requires amphp/http-server-router,
// amphp/http-server-static-content and amphp/log to be installed.

use Amp\Http\Server\SocketHttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket;
use Amp\Websocket\Client;
use Amp\Websocket\Server\ClientHandler;
use Amp\Websocket\Server\Gateway;
use Amp\Websocket\Server\OriginHandshakeHandler;
use Amp\Websocket\Server\Websocket;
use Monolog\Logger;
use function Amp\ByteStream\getStdout;

require __DIR__ . '/../../vendor/autoload.php';

$logHandler = new StreamHandler(getStdout());
$logHandler->setFormatter(new ConsoleFormatter);
$logger = new Logger('server');
$logger->pushHandler($logHandler);

$server = new SocketHttpServer($logger);

$server->expose(new Socket\InternetAddress('127.0.0.1', 1337));
$server->expose(new Socket\InternetAddress('[::1]', 1337));

$handshakeHandler = new OriginHandshakeHandler(
    ['http://localhost:1337', 'http://127.0.0.1:1337', 'http://[::1]:1337'],
);

$clientHandler = new class implements ClientHandler {
    public function handleClient(Gateway $gateway, Client $client, Request $request, Response $response): void
    {
        while ($message = $client->receive()) {
            $gateway->broadcast(\sprintf('%d: %s', $client->getId(), $message->buffer()));
        }
    }
};

$websocket = new Websocket($logger, $handshakeHandler, $clientHandler);

$router = new Router($server);
$router->addRoute('GET', '/broadcast', $websocket);
$router->setFallback(new DocumentRoot($server, __DIR__ . '/public'));

$server->start($router);

// Await SIGINT or SIGTERM to be received.
$signal = Amp\trapSignal([\SIGINT, \SIGTERM]);

$logger->info(\sprintf("Received signal %d, stopping HTTP server", $signal));

$server->stop();
