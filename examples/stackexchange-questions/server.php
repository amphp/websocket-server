<?php

// Note that this example requires amphp/artax, amphp/http-server-router,
// amphp/http-server-static-content and amphp/log to be installed.

use Amp\Http\Client\HttpClient;
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
use Amp\Loop;
use Amp\Promise;
use Amp\Socket\Server as SocketServer;
use Amp\Success;
use Amp\Websocket\Client;
use Amp\Websocket\Server\ClientHandler;
use Amp\Websocket\Server\Endpoint;
use Amp\Websocket\Server\Websocket;
use Monolog\Logger;
use function Amp\ByteStream\getStdout;
use function Amp\call;

require __DIR__ . '/../../vendor/autoload.php';

Loop::run(function (): Promise {
    $websocket = new Websocket(new class implements ClientHandler {
        /** @var string|null */
        private $watcher;

        /** @var HttpClient */
        private $http;

        /** @var int|null */
        private $newestQuestion;

        public function onStart(Endpoint $endpoint): Promise
        {
            $this->http = HttpClientBuilder::buildDefault();
            $this->watcher = Loop::repeat(10000, function () use ($endpoint) {
                /** @var Response $response */
                $response = yield $this->http->request(
                    new ClientRequest('https://api.stackexchange.com/2.2/questions?order=desc&sort=activity&site=stackoverflow')
                );
                $json = yield $response->getBody()->buffer();

                $data = \json_decode($json, true);

                if (!isset($data['items'])) {
                    return;
                }

                foreach (\array_reverse($data['items']) as $question) {
                    if ($this->newestQuestion === null || $question['question_id'] > $this->newestQuestion) {
                        $this->newestQuestion = $question['question_id'];
                        $endpoint->broadcast(\json_encode($question));
                    }
                }
            });

            return new Success;
        }

        public function onStop(Endpoint $endpoint): Promise
        {
            Loop::cancel($this->watcher);
            return new Success;
        }

        public function handleHandshake(Endpoint $endpoint, Request $request, Response $response): Promise
        {
            if (!\in_array($request->getHeader('origin'), ['http://localhost:1337', 'http://127.0.0.1:1337', 'http://[::1]:1337'], true)) {
                return $endpoint->getErrorHandler()->handleError(Status::FORBIDDEN, 'Origin forbidden', $request);
            }

            return new Success($response);
        }

        public function handleClient(Endpoint $endpoint, Client $client, Request $request, Response $response): Promise
        {
            return call(function () use ($client) {
                while ($message = yield $client->receive()) {
                    // Messages received on the connection are ignored and discarded.
                }
            });
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

    return $server->start();
});
