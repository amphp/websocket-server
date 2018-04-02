<?php

// Note that this example requires amphp/artax, amphp/http-router, and amphp/http-file-server to be installed.

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Http\Server\Server;
use Amp\Http\Server\Websocket\Websocket;
use Amp\Loop;
use Amp\Promise;
use Amp\Socket;
use Psr\Log\NullLogger;

require __DIR__ . "/../../vendor/autoload.php";

$websocket = new class extends Websocket {
    /** @var string|null */
    private $watcher;

    /** @var Client */
    private $http;

    /** @var int|null */
    private $newestQuestion;

    public function onStart(Server $server): Promise {
        $promise = parent::onStart($server);
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

        return $promise;
    }

    public function onHandshake(Request $request, Response $response) {
        if ($request->getHeader("origin") !== "http://localhost:1337") {
            $response->setStatus(403);
        }

        return $response;
    }

    public function onStop(Server $server): Promise {
        Loop::cancel($this->watcher);
        return parent::onStop($server);
    }
};

$router = new Router;
$router->addRoute("GET", "/live", $websocket);
$router->setFallback(new DocumentRoot(__DIR__ . "/public"));

$sockets = [
    Socket\listen("127.0.0.1:1337"),
];

$server = new Server($sockets, $router, new NullLogger);

Loop::run(function () use ($server) {
    yield $server->start();
});