<?php

require dirname(__DIR__) . "/vendor/autoload.php";

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\Server;
use Amp\Http\Server\Websocket;
use Amp\Socket;
use Psr\Log\NullLogger;

Amp\Loop::run(function () {
    /* --- http://localhost:9001/ ------------------------------------------------------------------- */

    $websocket = new Websocket\Websocket(new class implements Websocket\Application {
        /** @var \Amp\Http\Server\Websocket\Endpoint */
        private $endpoint;

        public function onStart(Websocket\Endpoint $endpoint) {
            $this->endpoint = $endpoint;
        }

        public function onHandshake(Request $request, Response $response) {
            return $response;
        }

        public function onOpen(int $clientId, Request $request) { }

        public function onData(int $clientId, Websocket\Message $message) {
            if ($message->isBinary()) {
                $this->endpoint->broadcastBinary(yield $message->buffer());
            } else {
                $this->endpoint->broadcast(yield $message->buffer());
            }
        }

        public function onClose(int $clientId, int $code, string $reason) { }

        public function onStop() { }
    });

    $websocket->setBytesPerMinuteLimit(PHP_INT_MAX);
    $websocket->setFrameSizeLimit(PHP_INT_MAX);
    $websocket->setFramesPerSecondLimit(PHP_INT_MAX);
    $websocket->setMessageSizeLimit(PHP_INT_MAX);
    $websocket->setValidateUtf8(true);

    $server = new Server([Socket\listen("127.0.0.1:9001")], $websocket, new NullLogger);
    return $server->start();
});
