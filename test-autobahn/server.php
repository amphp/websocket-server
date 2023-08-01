<?php

require \dirname(__DIR__) . "/vendor/autoload.php";

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket;
use Amp\Websocket\Parser\Rfc6455ParserFactory;
use Amp\Websocket\Server\EmptyWebsocketHandshakeHandler;
use Amp\Websocket\Server\Rfc6455ClientFactory;
use Amp\Websocket\Server\Websocket;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;
use Psr\Log\NullLogger;

/* --- http://localhost:9001/ ------------------------------------------------------------------- */

$logger = new NullLogger();

$websocket = new Websocket(
    logger: $logger,
    handshakeHandler: new EmptyWebsocketHandshakeHandler(),
    clientHandler: new class implements WebsocketClientHandler {
        public function handleClient(WebsocketClient $client, Request $request, Response $response): void
        {
            while ($message = $client->receive()) {
                if ($message->isBinary()) {
                    $client->sendBinary($message->buffer());
                } else {
                    $client->send($message->buffer());
                }
            }
        }
    },
    clientFactory: new Rfc6455ClientFactory(
        heartbeatQueue: null,
        rateLimiter: null,
        parserFactory: new Rfc6455ParserFactory(
            validateUtf8: true,
            messageSizeLimit: \PHP_INT_MAX,
            frameSizeLimit: \PHP_INT_MAX,
        ),
    ),
);

$server = SocketHttpServer::createForDirectAccess($logger);
$server->expose(new Socket\InternetAddress("127.0.0.1", 9001));

$server->start($websocket, new DefaultErrorHandler());

$input = Amp\ByteStream\getStdin()->read();

$server->stop();
