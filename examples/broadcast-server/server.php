<?php declare(strict_types=1);

// Note that this example requires amphp/http-server-router,
// amphp/http-server-static-content and amphp/log to be installed.

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\SocketHttpServer;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket;
use Amp\Websocket\Compression\Rfc7692CompressionFactory;
use Amp\Websocket\Server\AllowOriginAcceptor;
use Amp\Websocket\Server\Websocket;
use Amp\Websocket\Server\WebsocketClientGateway;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\Server\WebsocketGateway;
use Amp\Websocket\WebsocketClient;
use Monolog\Logger;
use function Amp\ByteStream\getStdout;

require __DIR__ . '/../../vendor/autoload.php';

$logHandler = new StreamHandler(getStdout());
$logHandler->setFormatter(new ConsoleFormatter);
$logger = new Logger('server');
$logger->pushHandler($logHandler);

$server = SocketHttpServer::createForDirectAccess($logger);

$server->expose(new Socket\InternetAddress('127.0.0.1', 1337));
$server->expose(new Socket\InternetAddress('[::1]', 1337));

$acceptor = new AllowOriginAcceptor(
    ['http://localhost:1337', 'http://127.0.0.1:1337', 'http://[::1]:1337'],
);

$clientHandler = new class implements WebsocketClientHandler {
    public function __construct(
        private readonly WebsocketGateway $gateway = new WebsocketClientGateway(),
    ) {
    }

    public function handleClient(WebsocketClient $client, Request $request, Response $response): void
    {
        $this->gateway->addClient($client);

        while ($message = $client->receive()) {
            $this->gateway->broadcastText(sprintf('%d: %s', $client->getId(), (string) $message))->ignore();
        }
    }
};

$websocket = new Websocket(
    httpServer: $server,
    logger: $logger,
    acceptor: $acceptor,
    clientHandler: $clientHandler,
    compressionFactory: new Rfc7692CompressionFactory(),
);

$errorHandler = new DefaultErrorHandler();

$router = new Router($server, $logger, $errorHandler);
$router->addRoute('GET', '/broadcast', $websocket);
$router->setFallback(new DocumentRoot($server, $errorHandler, __DIR__ . '/public'));

$server->start($router, $errorHandler);

// Await SIGINT or SIGTERM to be received.
$signal = Amp\trapSignal([\SIGINT, \SIGTERM]);

$logger->info(sprintf("Received signal %d, stopping HTTP server", $signal));

$server->stop();
