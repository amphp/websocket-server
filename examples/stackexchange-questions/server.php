<?php

// Note that this example requires amphp/http-client, amphp/http-server-router,
// amphp/http-server-static-content and amphp/log to be installed.

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request as ClientRequest;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\SocketHttpServer;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket;
use Amp\Websocket\Client;
use Amp\Websocket\Server\ClientGateway;
use Amp\Websocket\Server\ClientHandler;
use Amp\Websocket\Server\Gateway;
use Amp\Websocket\Server\OriginHandshakeHandler;
use Amp\Websocket\Server\Websocket;
use Monolog\Logger;
use Revolt\EventLoop;
use function Amp\ByteStream\getStdout;

require __DIR__ . '/../../vendor/autoload.php';

$logHandler = new StreamHandler(getStdout());
$logHandler->setFormatter(new ConsoleFormatter);
$logger = new Logger('server');
$logger->pushHandler($logHandler);

$server = new SocketHttpServer($logger);

$server->expose(new Socket\InternetAddress('127.0.0.1', 1337));
$server->expose(new Socket\InternetAddress('[::1]', 1337));

$gateway = new ClientGateway();

$errorHandler = new DefaultErrorHandler();

$handshakeHandler = new OriginHandshakeHandler(
    ['http://localhost:1337', 'http://127.0.0.1:1337', 'http://[::1]:1337'],
);

$clientHandler = new class ($server, $gateway) implements ClientHandler {
    private ?string $watcher = null;
    private ?int $newestQuestion = null;

    public function __construct(
        HttpServer $server,
        private readonly Gateway $gateway,
    ) {
        $server->onStart($this->onStart(...));
        $server->onStop($this->onStop(...));
    }

    public function onStart(): void
    {
        $client = HttpClientBuilder::buildDefault();
        $this->watcher = EventLoop::repeat(10, function () use ($client): void {
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
                    $this->gateway->broadcast(\json_encode($question));
                }
            }
        });
    }

    public function onStop(): void
    {
        if ($this->watcher) {
            EventLoop::cancel($this->watcher);
        }
    }

    public function handleClient(Gateway $gateway, Client $client, Request $request, Response $response): void
    {
        while ($message = $client->receive()) {
            // Messages received on the connection are ignored and discarded.
        }
    }
};

$websocket = new Websocket(
    logger: $logger,
    handshakeHandler: $handshakeHandler,
    clientHandler: $clientHandler,
    gateway: $gateway,
);

$router = new Router($server, $errorHandler);
$router->addRoute('GET', '/broadcast', $websocket);
$router->setFallback(new DocumentRoot($server, $errorHandler, __DIR__ . '/public'));

$server->start($router, $errorHandler);

// Await SIGINT or SIGTERM to be received.
$signal = Amp\trapSignal([\SIGINT, \SIGTERM]);

$logger->info(\sprintf("Received signal %d, stopping HTTP server", $signal));

$server->stop();
