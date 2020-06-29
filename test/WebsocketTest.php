<?php

namespace Amp\Websocket\Server\Test;

use Amp\ByteStream;
use Amp\Deferred;
use Amp\Http\Rfc7230;
use Amp\Http\Server\Driver\Client as HttpClient;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Promise;
use Amp\Socket;
use Amp\Socket\Server;
use Amp\Success;
use Amp\Websocket\Client;
use Amp\Websocket\Server\ClientFactory;
use Amp\Websocket\Server\ClientHandler;
use Amp\Websocket\Server\Gateway;
use Amp\Websocket\Server\Websocket;
use Amp\Websocket\Server\WebsocketObserver;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\UriInterface as PsrUri;
use Psr\Log\NullLogger;
use function Amp\asyncCall;
use function Amp\call;

class WebsocketTest extends AsyncTestCase
{
    protected function execute(callable $onConnect, Client $client): \Generator
    {
        \assert($client instanceof MockObject);

        $client->method('close')
            ->willReturn(new Success);

        $factory = $this->createMock(ClientFactory::class);
        $factory->method('createClient')
            ->willReturn($client);

        $server = Server::listen("127.0.0.1:0");

        $deferred = new Deferred;

        $webserver = $this->createWebsocketServer(
            $server,
            $factory,
            function (Websocket $websocket, Client $client) use ($onConnect, $deferred): Promise {
                $promise = call($onConnect, $websocket, $client);
                $deferred->resolve($promise);
                return $promise;
            }
        );

        yield $webserver->start();

        $socket = yield Socket\connect($server->getAddress()->toString());
        \assert($socket instanceof Socket\EncryptableSocket);

        $request = $this->createRequest();
        yield $socket->write($this->writeRequest($request));

        asyncCall(function () use ($socket): \Generator {
            while (null !== yield $socket->read());
        });

        try {
            yield $deferred->promise();
        } finally {
            yield $webserver->stop();
            $socket->close();
        }
    }

    protected function createWebsocketServer(Server $server, ClientFactory $factory, callable $onConnect): HttpServer
    {
        $websocket = new Websocket(new class($onConnect) implements ClientHandler {
            private $onConnect;

            public function __construct(callable $onConnect)
            {
                $this->onConnect = $onConnect;
            }

            public function handleHandshake(Gateway $websocket, Request $request, Response $response): Promise
            {
                return new Success($response);
            }

            public function handleClient(Gateway $gateway, Client $client, Request $request, Response $response): Promise
            {
                return call($this->onConnect, $gateway, $client);
            }
        }, null, null, $factory);

        return new HttpServer(
            [$server],
            $websocket,
            new NullLogger
        );
    }

    /**
     * @param Request $request Request initiating the handshake.
     * @param int     $status Expected status code.
     * @param array   $expectedHeaders Expected response headers.
     *
     * @dataProvider provideHandshakes
     */
    public function testHandshake(Request $request, int $status, array $expectedHeaders = []): \Generator
    {
        $clientHandler = $this->createMock(ClientHandler::class);

        $clientHandler->expects($status === Status::SWITCHING_PROTOCOLS ? $this->once() : $this->never())
            ->method('handleHandshake')
            ->willReturnCallback(function (Gateway $endpoint, Request $request, Response $response): Promise {
                return new Success($response);
            });

        $websocket = new Websocket($clientHandler);

        $server = new HttpServer([Server::listen('127.0.0.1:0')], $websocket, new NullLogger);
        yield $server->start();

        try {
            /** @var Response $response */
            $response = yield $websocket->handleRequest($request);
            $this->assertEquals($expectedHeaders, \array_intersect_key($response->getHeaders(), $expectedHeaders));

            if ($status === Status::SWITCHING_PROTOCOLS) {
                $this->assertEmpty(yield ByteStream\buffer($response->getBody()));
            }
        } finally {
            $server->stop();
        }
    }

    public function createRequest(): Request
    {
        $headers = [
            "host" => ["localhost"],
            "sec-websocket-key" => ["x3JJHMbDL1EzLkh9GBhXDw=="],
            "sec-websocket-version" => ["13"],
            "upgrade" => ["websocket"],
            "connection" => ["upgrade"],
        ];

        $uri = $this->createMock(PsrUri::class);
        $uri->method('getPath')
            ->willReturn('/');

        return new Request($this->createMock(HttpClient::class), "GET", $uri, $headers);
    }

    public function writeRequest(Request $request): string
    {
        return \sprintf(
            "%s %s HTTP/1.1\r\n%s\r\n",
            $request->getMethod(),
            $request->getUri()->getPath(),
            Rfc7230::formatHeaders($request->getHeaders())
        );
    }

    public function provideHandshakes(): array
    {
        $testCases = [];

        // 0 ----- valid Handshake request -------------------------------------------------------->
        $request = $this->createRequest();
        $testCases[] = [$request, Status::SWITCHING_PROTOCOLS, [
            "upgrade" => ["websocket"],
            "connection" => ["upgrade"],
            "sec-websocket-accept" => ["HSmrc0sMlYUkAGmm5OPpG2HaGWk="],
        ]];

        // 1 ----- error conditions: Handshake with POST method ----------------------------------->
        $request = $this->createRequest();
        $request->setMethod("POST");
        $testCases[] = [$request, Status::METHOD_NOT_ALLOWED, ["allow" => ["GET"]]];

        // 2 ----- error conditions: Handshake with 1.0 protocol ---------------------------------->
        $request = $this->createRequest();
        $request->setProtocolVersion("1.0");
        $testCases[] = [$request, Status::HTTP_VERSION_NOT_SUPPORTED, ["upgrade" => ["websocket"]]];

        // 3 ----- error conditions: Handshake with non-empty body -------------------------------->
        $request = $this->createRequest();
        $request->setBody(new ByteStream\InMemoryStream("Non-empty body"));
        $testCases[] = [$request, Status::BAD_REQUEST];

        // 4 ----- error conditions: Upgrade: Websocket header required --------------------------->
        $request = $this->createRequest();
        $request->setHeader("upgrade", "no websocket!");
        $testCases[] = [$request, Status::UPGRADE_REQUIRED, ["upgrade" => ["websocket"]]];

        // 5 ----- error conditions: Connection: Upgrade header required -------------------------->
        $request = $this->createRequest();
        $request->setHeader("connection", "no upgrade!");
        $testCases[] = [$request, Status::UPGRADE_REQUIRED];

        // 6 ----- error conditions: Sec-Websocket-Key header required ---------------------------->
        $request = $this->createRequest();
        $request->removeHeader("sec-websocket-key");
        $testCases[] = [$request, Status::BAD_REQUEST];

        // 7 ----- error conditions: Sec-Websocket-Version header must be 13 ---------------------->
        $request = $this->createRequest();
        $request->setHeader("sec-websocket-version", "12");
        $testCases[] = [$request, Status::BAD_REQUEST, ["sec-websocket-version" => ["13"]]];

        return $testCases;
    }

    public function testInvalidOnHandshake(): \Generator
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("handleHandshake() must resolve to an instance of Amp\\Http\\Server\\Response");

        $clientHandler = $this->createMock(ClientHandler::class);

        $clientHandler->expects($this->once())
            ->method('handleHandshake')
            ->willReturn(new Success(false));

        $websocket = new Websocket($clientHandler);

        $server = new HttpServer([Server::listen('127.0.0.1:0')], $websocket, new NullLogger);
        yield $server->start();

        try {
            $response = yield $websocket->handleRequest($this->createRequest());
        } finally {
            $server->stop();
        }
    }

    public function testBroadcast(): \Generator
    {
        $client = $this->createMock(Client::class);
        $client->method('getRemoteAddress')
            ->willReturn(new Socket\SocketAddress('127.0.0.1', 1));
        $client->expects($this->once())
            ->method('send')
            ->with('Text');
        $client->expects($this->once())
            ->method('sendBinary')
            ->with('Binary');

        return $this->execute(function (Websocket $websocket, Client $client) {
            $websocket->broadcast('Text');
            $websocket->broadcastBinary('Binary');
        }, $client);
    }

    public function testBroadcastExcept(): \Generator
    {
        $client = $this->createMock(Client::class);
        $client->method('getRemoteAddress')
            ->willReturn(new Socket\SocketAddress('127.0.0.1', 1));
        $client->expects($this->never())
            ->method('send')
            ->with('Text');
        $client->expects($this->never())
            ->method('sendBinary')
            ->with('Binary');

        return $this->execute(function (Websocket $websocket, Client $client) {
            $websocket->broadcast('Text', [$client->getId()]);
            $websocket->broadcastBinary('Binary', [$client->getId()]);
        }, $client);
    }

    public function testMulticast(): \Generator
    {
        $client = $this->createMock(Client::class);
        $client->method('getRemoteAddress')
            ->willReturn(new Socket\SocketAddress('127.0.0.1', 1));
        $client->expects($this->once())
            ->method('send')
            ->with('Text');
        $client->expects($this->once())
            ->method('sendBinary')
            ->with('Binary');

        return $this->execute(function (Websocket $websocket, Client $client) {
            $websocket->multicast('Text', [$client->getId()]);
            $websocket->multicastBinary('Binary', [$client->getId()]);
        }, $client);
    }

    public function testWebsocketObserverAttachment(): \Generator
    {
        $websocket = new Websocket($this->createMock(ClientHandler::class));

        $webserver = new HttpServer([Server::listen('127.0.0.1:0')], $websocket, new NullLogger);

        $observer = $this->createMock(WebsocketObserver::class);
        $observer->expects($this->once())
            ->method('onStart')
            ->willReturn(new Success);
        $observer->expects($this->once())
            ->method('onStop')
            ->willReturn(new Success);

        $websocket->attach($observer);
        $websocket->attach($observer); // Attaching same object should be ignored.

        yield $webserver->start();

        yield $webserver->stop();
    }
}
