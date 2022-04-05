<?php

namespace Amp\Websocket\Server\Test;

use Amp\ByteStream;
use Amp\DeferredFuture;
use Amp\Http\Rfc7230;
use Amp\Http\Server\Driver\Client as HttpClient;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Http\Status;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket;
use Amp\Websocket\Client;
use Amp\Websocket\Server\ClientFactory;
use Amp\Websocket\Server\ClientHandler;
use Amp\Websocket\Server\Gateway;
use Amp\Websocket\Server\Websocket;
use Amp\Websocket\Server\WebsocketServerObserver;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\UriInterface as PsrUri;
use Psr\Log\NullLogger;
use Revolt\EventLoop;

class WebsocketTest extends AsyncTestCase
{
    protected function execute(callable $onConnect, Client $client): void
    {
        \assert($client instanceof MockObject);

        $factory = $this->createMock(ClientFactory::class);
        $factory->method('createClient')
            ->willReturn($client);

        $deferred = new DeferredFuture;

        $webserver = $this->createWebsocketServer(
            $factory,
            function (Gateway $gateway, Client $client) use ($onConnect, $deferred): void {
                $deferred->complete($onConnect($gateway, $client));
            }
        );

        $server = $webserver->getServers()[0] ?? self::fail('HTTP server did not create any socket servers');

        $socket = Socket\connect($server->getAddress()->toString());
        \assert($socket instanceof Socket\EncryptableSocket);

        $request = $this->createRequest();
        $socket->write($this->writeRequest($request));

        EventLoop::queue(function () use ($socket): void {
            while (null !== $socket->read());
        });

        $deferred->getFuture()
            ->finally($webserver->stop(...))
            ->finally($socket->close(...))
            ->await();
    }

    protected function createWebsocketServer(
        ClientFactory $factory,
        callable $onConnect
    ): SocketHttpServer {
        $httpServer = new SocketHttpServer(new NullLogger);

        $websocket = new Websocket($httpServer, new class($onConnect) implements ClientHandler {
            private $onConnect;

            public function __construct(callable $onConnect)
            {
                $this->onConnect = $onConnect;
            }

            public function handleHandshake(Gateway $gateway, Request $request, Response $response): Response
            {
                return $response;
            }

            public function handleClient(Gateway $gateway, Client $client, Request $request, Response $response): void
            {
                ($this->onConnect)($gateway, $client);
            }
        }, clientFactory: $factory);

        $httpServer->expose(new Socket\InternetAddress('127.0.0.1', 0));

        $httpServer->start($websocket);

        return $httpServer;
    }

    /**
     * @param Request $request Request initiating the handshake.
     * @param int     $status Expected status code.
     * @param array   $expectedHeaders Expected response headers.
     *
     * @dataProvider provideHandshakes
     */
    public function testHandshake(Request $request, int $status, array $expectedHeaders = []): void
    {
        $clientHandler = $this->createMock(ClientHandler::class);

        $clientHandler->expects($status === Status::SWITCHING_PROTOCOLS ? $this->once() : $this->never())
            ->method('handleHandshake')
            ->willReturnCallback(function (Gateway $endpoint, Request $request, Response $response): Response {
                return $response;
            });

        $server = new SocketHttpServer(new NullLogger);
        $server->expose(new Socket\InternetAddress('127.0.0.1', 0));
        $websocket = new Websocket($server, $clientHandler);
        $server->start($websocket);

        try {
            $response = $websocket->handleRequest($request);
            $this->assertEquals($expectedHeaders, \array_intersect_key($response->getHeaders(), $expectedHeaders));

            if ($status === Status::SWITCHING_PROTOCOLS) {
                $this->assertEmpty(ByteStream\buffer($response->getBody()));
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
        $testCases['Valid'] = [$request, Status::SWITCHING_PROTOCOLS, [
            "upgrade" => ["websocket"],
            "connection" => ["upgrade"],
            "sec-websocket-accept" => ["HSmrc0sMlYUkAGmm5OPpG2HaGWk="],
        ]];

        // 1 ----- error conditions: Handshake with POST method ----------------------------------->
        $request = $this->createRequest();
        $request->setMethod("POST");
        $testCases['POST'] = [$request, Status::METHOD_NOT_ALLOWED, ["allow" => ["GET"]]];

        // 2 ----- error conditions: Handshake with 1.0 protocol ---------------------------------->
        $request = $this->createRequest();
        $request->setProtocolVersion("1.0");
        $testCases['HTTP/1.0 Protocol'] = [$request, Status::HTTP_VERSION_NOT_SUPPORTED, ["upgrade" => ["websocket"]]];

        // 3 ----- error conditions: Handshake with non-empty body -------------------------------->
        $request = $this->createRequest();
        $request->setBody(new ByteStream\ReadableBuffer("Non-empty body"));
        $testCases['Non-empty Body'] = [$request, Status::BAD_REQUEST];

        // 4 ----- error conditions: Upgrade: Websocket header required --------------------------->
        $request = $this->createRequest();
        $request->setHeader("upgrade", "no websocket!");
        $testCases['No Upgrade Header'] = [$request, Status::UPGRADE_REQUIRED, ["upgrade" => ["websocket"]]];

        // 5 ----- error conditions: Connection: Upgrade header required -------------------------->
        $request = $this->createRequest();
        $request->setHeader("connection", "no upgrade!");
        $testCases['No Connection Header'] = [$request, Status::UPGRADE_REQUIRED];

        // 6 ----- error conditions: Sec-Websocket-Key header required ---------------------------->
        $request = $this->createRequest();
        $request->removeHeader("sec-websocket-key");
        $testCases['No Sec-websocket-key Header'] = [$request, Status::BAD_REQUEST];

        // 7 ----- error conditions: Sec-Websocket-Version header must be 13 ---------------------->
        $request = $this->createRequest();
        $request->setHeader("sec-websocket-version", "12");
        $testCases['Invalid Sec-websocket-version Header'] = [$request, Status::BAD_REQUEST, ["sec-websocket-version" => ["13"]]];

        return $testCases;
    }

    public function testBroadcast(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('getRemoteAddress')
            ->willReturn(new Socket\InternetAddress('127.0.0.1', 1));
        $client->expects($this->once())
            ->method('send')
            ->with('Text');
        $client->expects($this->once())
            ->method('sendBinary')
            ->with('Binary');
        $client->method('isClosed')
            ->willReturn(false);

        $this->execute(function (Gateway $gateway, Client $client) {
            $gateway->broadcast('Text')->await();
            $gateway->broadcastBinary('Binary')->await();
        }, $client);
    }

    public function testBroadcastExcept(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('getRemoteAddress')
            ->willReturn(new Socket\InternetAddress('127.0.0.1', 1));
        $client->expects($this->never())
            ->method('send');
        $client->expects($this->never())
            ->method('sendBinary');
        $client->method('isClosed')
            ->willReturn(false);

        $this->execute(function (Gateway $gateway, Client $client) {
            $gateway->broadcast('Text', [$client->getId()])->await();
            $gateway->broadcastBinary('Binary', [$client->getId()])->await();
        }, $client);
    }

    public function testMulticast(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('getRemoteAddress')
            ->willReturn(new Socket\InternetAddress('127.0.0.1', 1));
        $client->expects($this->once())
            ->method('send')
            ->with('Text');
        $client->expects($this->once())
            ->method('sendBinary')
            ->with('Binary');
        $client->method('isClosed')
            ->willReturn(false);

        $this->execute(function (Gateway $gateway, Client $client) {
            $gateway->multicast('Text', [$client->getId()])->await();
            $gateway->multicastBinary('Binary', [$client->getId()])->await();
        }, $client);
    }

    public function testWebsocketObserverAttachment(): void
    {
        $webserver = new SocketHttpServer(new NullLogger);
        $webserver->expose(new Socket\InternetAddress('127.0.0.1', 0));

        $websocket = new Websocket($webserver, $this->createMock(ClientHandler::class));

        $observer = $this->createMock(WebsocketServerObserver::class);
        $observer->expects($this->once())
            ->method('onStart');
        $observer->expects($this->once())
            ->method('onStop');

        $websocket->attach($observer);
        $websocket->attach($observer); // Attaching same object should be ignored.

        $webserver->start($websocket);

        $webserver->stop();
    }
}
