<?php

// Note that this example requires amphp/artax, amphp/http-server-router,
// amphp/http-server-static-content and amphp/log to be installed.

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request as ClientRequest;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Http\Status;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket\Server as SocketServer;
use Amp\Websocket\Client;
use Amp\Websocket\Server\ClientHandler;
use Amp\Websocket\Server\Gateway;
use Amp\Websocket\Server\Websocket;
use Amp\Websocket\Server\WebsocketServerObserver;
use Monolog\Logger;
use Revolt\EventLoop;
use function Amp\ByteStream\getStdout;

require __DIR__ . '/../../vendor/autoload.php';

$websocket = new Websocket(new class implements ClientHandler, WebsocketServerObserver {
    private string $watcher;
    private ?int $newestQuestion = null;

    public function onStart(HttpServer $server, Gateway $gateway): void
    {
        $client = HttpClientBuilder::buildDefault();
        $this->watcher = EventLoop::repeat(10, function () use ($client, $gateway): void {
            $response = $client->request(
                new ClientRequest('https://api.stackexchange.com/2.2/questions?order=desc&sort=activity&site=stackoverflow')
            );
            $json = $response->getBody()->buffer();

            $data = \json_decode($json, true);

            if (!isset($data['items'])) {
                return;
            }

            foreach (\array_reverse($data['items']) as $question) {
                if ($this->newestQuestion === null || $question['question_id'] > $this->newestQuestion) {
                    $this->newestQuestion = $question['question_id'];
                    $gateway->broadcast(\json_encode($question));
                }
            }
        });
    }

    public function onStop(HttpServer $server, Gateway $gateway): void
    {
        EventLoop::cancel($this->watcher);
    }

    public function handleHandshake(Gateway $gateway, Request $request, Response $response): Response
    {
        if (!\in_array($request->getHeader('origin'), ['http://localhost:1337', 'http://127.0.0.1:1337', 'http://[::1]:1337'], true)) {
            return $gateway->getErrorHandler()->handleError(Status::FORBIDDEN, 'Origin forbidden', $request);
        }

        return $response;
    }

    public function handleClient(Gateway $gateway, Client $client, Request $request, Response $response): void
    {
        while ($message = $client->receive()) {
            // Messages received on the connection are ignored and discarded.
        }
    }
});

$sockets = [
    SocketServer::listen('127.0.0.1:1337'),
    SocketServer::listen('[::1]:1337'),
];

$router = new Router;
$router->addRoute('GET', '/live', $websocket);
$router->setFallback(new DocumentRoot(__DIR__ . '/public'));

$logHandler = new StreamHandler(getStdout());
$logHandler->setFormatter(new ConsoleFormatter);
$logger = new Logger('server');
$logger->pushHandler($logHandler);

$server = new HttpServer($sockets, $router, $logger);

$server->start();

// Await SIGINT or SIGTERM to be received.
$signal = Amp\trapSignal([\SIGINT, \SIGTERM]);

$logger->info(\sprintf("Received signal %d, stopping HTTP server", $signal));

$server->stop();
