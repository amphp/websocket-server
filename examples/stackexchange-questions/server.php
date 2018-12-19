<?php

// Note that this example requires amphp/artax, amphp/http-server-router,
// amphp/http-server-static-content and amphp/log to be installed.

use Amp\Artax\Client;
use Amp\ByteStream\ResourceOutputStream;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\Server;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Http\Server\Websocket\Message;
use Amp\Http\Server\Websocket\Websocket;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Loop;
use Amp\Promise;
use Amp\Socket;
use Amp\Success;
use Monolog\Logger;

require __DIR__ . "/../../vendor/autoload.php";

$websocket = new class extends Websocket
{
    /** @var string|null */
    private $watcher;

    /** @var Client */
    private $http;

    /** @var int|null */
    private $newestQuestion;

    protected function afterOnStart(Server $server): Promise
    {
        $this->http = new Amp\Artax\DefaultClient;
        $this->watcher = Loop::repeat(10000, function () {
            /** @var Response $response */
            $response = yield $this->http->request('https://api.stackexchange.com/2.2/questions?order=desc&sort=activity&site=stackoverflow');
            $json = yield $response->getBody();

            $data = \json_decode($json, true);

            foreach (\array_reverse($data["items"]) as $question) {
                if ($this->newestQuestion === null || $question["question_id"] > $this->newestQuestion) {
                    $this->newestQuestion = $question["question_id"];
                    $this->broadcast(\json_encode($question));
                }
            }
        });

        return new Success;
    }

    protected function beforeOnStop(Server $server): Promise
    {
        Loop::cancel($this->watcher);

        return new Success;
    }

    public function onHandshake(Request $request, Response $response)
    {
        if ($request->getHeader("origin") !== "http://localhost:1337") {
            $response->setStatus(403);
        }

        return $response;
    }

    public function onOpen(int $clientId, Request $request)
    {
        // do nothing
    }

    public function onData(int $clientId, Message $message)
    {
        // do nothing
    }

    public function onClose(int $clientId, int $code, string $reason)
    {
        // do nothing
    }
};

$servers = [
    Socket\listen("0.0.0.0:1337"),
    Socket\listen("[::]:1337"),
];

$router = new Router;
$router->addRoute("GET", "/live", $websocket);
$router->setFallback(new DocumentRoot(__DIR__ . "/public"));

$logHandler = new StreamHandler(new ResourceOutputStream(\STDOUT));
$logHandler->setFormatter(new ConsoleFormatter);
$logger = new Logger('server');
$logger->pushHandler($logHandler);
$sockets = [
    Socket\listen("127.0.0.1:1337"),
];

$server = new Server($sockets, $router, $logger);

Loop::run(function () use ($server) {
    yield $server->start();
});