<?php declare(strict_types=1);

require dirname(__DIR__) . "/vendor/autoload.php";

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket;
use Amp\Websocket\Parser\Rfc6455ParserFactory;
use Amp\Websocket\Server\Rfc6455Acceptor;
use Amp\Websocket\Server\Rfc6455ClientFactory;
use Amp\Websocket\Server\Websocket;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;
use Psr\Log\NullLogger;

/* --- http://localhost:9001/ ------------------------------------------------------------------- */

$logger = new NullLogger();

$server = SocketHttpServer::createForDirectAccess($logger);

$websocket = new Websocket(
    httpServer: $server,
    logger: $logger,
    acceptor: new Rfc6455Acceptor(),
    clientHandler: new class implements WebsocketClientHandler {
        public function handleClient(WebsocketClient $client, Request $request, Response $response): void
        {
            while ($message = $client->receive()) {
                if ($message->isBinary()) {
                    $client->sendBinary($message->buffer());
                } else {
                    $client->sendText($message->buffer());
                }
            }
        }
    },
    clientFactory: new Rfc6455ClientFactory(
        heartbeatQueue: null,
        rateLimit: null,
        parserFactory: new Rfc6455ParserFactory(
            validateUtf8: true,
            messageSizeLimit: \PHP_INT_MAX,
            frameSizeLimit: \PHP_INT_MAX,
        ),
    ),
);

$server->expose(new Socket\InternetAddress("127.0.0.1", 9001));

$server->start($websocket, new DefaultErrorHandler());

$input = Amp\ByteStream\getStdin()->read();

$server->stop();
