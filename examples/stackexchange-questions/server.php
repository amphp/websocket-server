<?php declare(strict_types=1);

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
use Amp\Websocket\Server\AllowOriginAcceptor;
use Amp\Websocket\Server\Websocket;
use Amp\Websocket\Server\WebsocketClientGateway;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\Server\WebsocketGateway;
use Amp\Websocket\WebsocketClient;
use Monolog\Logger;
use Revolt\EventLoop;
use function Amp\ByteStream\getStdout;

require __DIR__ . '/../../vendor/autoload.php';

$logHandler = new StreamHandler(getStdout());
$logHandler->setFormatter(new ConsoleFormatter);
$logger = new Logger('server');
$logger->pushHandler($logHandler);

$server = SocketHttpServer::createForDirectAccess($logger);

$server->expose(new Socket\InternetAddress('127.0.0.1', 1337));
$server->expose(new Socket\InternetAddress('[::1]', 1337));

$errorHandler = new DefaultErrorHandler();

$acceptor = new AllowOriginAcceptor(
    ['http://localhost:1337', 'http://127.0.0.1:1337', 'http://[::1]:1337'],
);

$clientHandler = new class($server) implements WebsocketClientHandler {
    private ?string $watcher = null;
    private ?int $newestQuestion = null;

    public function __construct(
        HttpServer $server,
        private readonly WebsocketGateway $gateway = new WebsocketClientGateway(),
    ) {
        $server->onStart($this->onStart(...));
        $server->onStop($this->onStop(...));
    }

    private function onStart(): void
    {
        $client = HttpClientBuilder::buildDefault();
        $this->watcher = EventLoop::repeat(10, function () use ($client): void {
            $response = $client->request(
                new ClientRequest('https://api.stackexchange.com/2.2/questions?order=desc&sort=activity&site=stackoverflow')
            );
            $json = $response->getBody()->buffer();

            $data = json_decode($json, true);

            if (!isset($data['items'])) {
                return;
            }

            foreach (array_reverse($data['items']) as $question) {
                if ($this->newestQuestion === null || $question['question_id'] > $this->newestQuestion) {
                    $this->newestQuestion = $question['question_id'];
                    $this->gateway->broadcastText(json_encode($question))->ignore();
                }
            }
        });
    }

    private function onStop(): void
    {
        if ($this->watcher) {
            EventLoop::cancel($this->watcher);
        }
    }

    public function handleClient(WebsocketClient $client, Request $request, Response $response): void
    {
        $this->gateway->addClient($client);

        while ($client->receive()) {
            // Messages received on the connection are ignored and discarded.
        }
    }
};

$websocket = new Websocket(
    httpServer: $server,
    logger: $logger,
    acceptor: $acceptor,
    clientHandler: $clientHandler,
);

$router = new Router($server, $logger, $errorHandler);
$router->addRoute('GET', '/live', $websocket);
$router->setFallback(new DocumentRoot($server, $errorHandler, __DIR__ . '/public'));

$server->start($router, $errorHandler);

// Await SIGINT or SIGTERM to be received.
$signal = Amp\trapSignal([\SIGINT, \SIGTERM]);

$logger->info(sprintf("Received signal %d, stopping HTTP server", $signal));

$server->stop();
