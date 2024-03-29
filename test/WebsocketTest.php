<?php declare(strict_types=1);

namespace Amp\Websocket\Server;

use Amp\ByteStream;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Http\Http1\Rfc7230;
use Amp\Http\HttpStatus;
use Amp\Http\Server\Driver\Client as HttpClient;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket;
use Amp\Websocket\Compression\Rfc7692CompressionFactory;
use Amp\Websocket\WebsocketClient;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\UriInterface as PsrUri;
use Psr\Log\NullLogger;
use Revolt\EventLoop;
use function Amp\delay;
use function Amp\Websocket\generateAcceptFromKey;
use function Amp\Websocket\generateKey;

class WebsocketTest extends AsyncTestCase
{
    /**
     * @param \Closure(WebsocketGateway, WebsocketClient):void $onConnect
     */
    protected function execute(\Closure $onConnect, WebsocketClient $client): void
    {
        \assert($client instanceof MockObject);

        $clientFactory = $this->createMock(WebsocketClientFactory::class);
        $clientFactory->method('createClient')
            ->willReturn($client);

        $deferred = new DeferredFuture;

        $webserver = $this->createWebsocketServer(
            $clientFactory,
            function (WebsocketGateway $gateway, WebsocketClient $client) use ($onConnect, $deferred): void {
                $deferred->complete($onConnect($gateway, $client));
            }
        );

        $server = $webserver->getServers()[0] ?? self::fail('HTTP server did not create any socket servers');

        $socket = Socket\connect($server->getAddress()->toString());

        $request = $this->createRequest();
        $socket->write($this->writeRequest($request));

        EventLoop::queue(function () use ($socket): void {
            while (null !== $socket->read()) {
                // discard
            }
        });

        $deferred->getFuture()
            ->finally($webserver->stop(...))
            ->finally($socket->close(...))
            ->await();
    }

    /**
     * @param \Closure(WebsocketGateway, WebsocketClient):void $clientHandler
     */
    protected function createWebsocketServer(
        WebsocketClientFactory $clientFactory,
        \Closure $clientHandler,
        WebsocketGateway $gateway = new WebsocketClientGateway(),
    ): SocketHttpServer {
        $logger = new NullLogger();
        $httpServer = SocketHttpServer::createForDirectAccess($logger);

        $websocket = new Websocket(
            httpServer: $httpServer,
            logger: $logger,
            acceptor: new Rfc6455Acceptor(),
            clientHandler: new class($clientHandler, $gateway) implements WebsocketClientHandler {
                public function __construct(
                    private readonly \Closure $clientHandler,
                    private readonly WebsocketGateway $gateway,
                ) {
                }

                public function handleClient(WebsocketClient $client, Request $request, Response $response): void
                {
                    $this->gateway->addClient($client);
                    ($this->clientHandler)($this->gateway, $client);
                }
            },
            clientFactory: $clientFactory,
        );

        $httpServer->expose(new Socket\InternetAddress('127.0.0.1', 0));

        $httpServer->start($websocket, $this->createMock(ErrorHandler::class));

        return $httpServer;
    }

    /**
     * @param Request $request Request initiating the handshake.
     * @param int $status Expected status code.
     * @param array $expectedHeaders Expected response headers.
     *
     * @dataProvider provideHandshakes
     */
    public function testHandshake(Request $request, int $status, array $expectedHeaders = []): void
    {
        $acceptor = $this->createMock(WebsocketAcceptor::class);

        $delegateHandler = new Rfc6455Acceptor();
        $acceptor->expects(self::once())
            ->method('handleHandshake')
            ->willReturnCallback($delegateHandler->handleHandshake(...));

        $logger = new NullLogger();
        $server = SocketHttpServer::createForDirectAccess($logger);
        $server->expose(new Socket\InternetAddress('127.0.0.1', 0));
        $websocket = new Websocket(
            httpServer: $server,
            logger: $logger,
            acceptor: $acceptor,
            clientHandler: $this->createMock(WebsocketClientHandler::class),
            compressionFactory: new Rfc7692CompressionFactory(),
        );
        $server->start($websocket, $this->createMock(ErrorHandler::class));

        try {
            $response = $websocket->handleRequest($request);
            $this->assertEquals($expectedHeaders, \array_intersect_key($response->getHeaders(), $expectedHeaders));

            if ($status === HttpStatus::SWITCHING_PROTOCOLS) {
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
            "sec-websocket-key" => [generateKey()],
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

    public function provideHandshakes(): iterable
    {
        // 0 ----- valid Handshake request -------------------------------------------------------->
        $request = $this->createRequest();
        yield 'Valid' => [
            $request,
            HttpStatus::SWITCHING_PROTOCOLS,
            [
                "upgrade" => ["websocket"],
                "connection" => ["upgrade"],
                "sec-websocket-accept" => [generateAcceptFromKey($request->getHeader('sec-websocket-key'))],
            ],
        ];

        // 1 ----- error conditions: Handshake with POST method ----------------------------------->
        $request = $this->createRequest();
        $request->setMethod("POST");
        yield 'POST' => [$request, HttpStatus::METHOD_NOT_ALLOWED, ["allow" => ["GET"]]];

        // 2 ----- error conditions: Handshake with 1.0 protocol ---------------------------------->
        $request = $this->createRequest();
        $request->setProtocolVersion("1.0");
        yield 'HTTP/1.0 Protocol' => [$request, HttpStatus::HTTP_VERSION_NOT_SUPPORTED, ["upgrade" => ["websocket"]]];

        // 3 ----- error conditions: Handshake with non-empty body -------------------------------->
        $request = $this->createRequest();
        $request->setBody(new ByteStream\ReadableBuffer("Non-empty body"));
        yield 'Non-empty Body' => [$request, HttpStatus::BAD_REQUEST];

        // 4 ----- error conditions: Upgrade: Websocket header required --------------------------->
        $request = $this->createRequest();
        $request->setHeader("upgrade", "no websocket!");
        yield 'No Upgrade Header' => [$request, HttpStatus::UPGRADE_REQUIRED, ["upgrade" => ["websocket"]]];

        // 5 ----- error conditions: Connection: Upgrade header required -------------------------->
        $request = $this->createRequest();
        $request->setHeader("connection", "no upgrade!");
        yield 'No Connection Header' => [$request, HttpStatus::UPGRADE_REQUIRED];

        // 6 ----- error conditions: Sec-Websocket-Key header required ---------------------------->
        $request = $this->createRequest();
        $request->removeHeader("sec-websocket-key");
        yield 'No Sec-websocket-key Header' => [$request, HttpStatus::BAD_REQUEST];

        // 7 ----- error conditions: Sec-Websocket-Version header must be 13 ---------------------->
        $request = $this->createRequest();
        $request->setHeader("sec-websocket-version", "12");
        yield 'Invalid Sec-websocket-version Header' => [
            $request,
            HttpStatus::BAD_REQUEST,
            ["sec-websocket-version" => ["13"]],
        ];

        // 8 ----- compression: valid header ------------------------------------------------------>
        $request = $this->createRequest();
        $request->setHeader("sec-websocket-extensions", "permessage-deflate; client_max_window_bits");
        yield 'With Valid Compression' => [
            $request,
            HttpStatus::SWITCHING_PROTOCOLS,
            [
                "upgrade" => ["websocket"],
                "connection" => ["upgrade"],
                "sec-websocket-accept" => [generateAcceptFromKey($request->getHeader('sec-websocket-key'))],
                "sec-websocket-extensions" => ["permessage-deflate; client_max_window_bits=15"],
            ],
        ];

        // 9 ----- compression: invalid header ---------------------------------------------------->
        $request = $this->createRequest();
        $request->setHeader("sec-websocket-extensions", "permessage-deflate; client_max_window_bits=8;");
        yield 'With Invalid Compression' => [
            $request,
            HttpStatus::SWITCHING_PROTOCOLS,
            [
                "upgrade" => ["websocket"],
                "connection" => ["upgrade"],
                "sec-websocket-accept" => [generateAcceptFromKey($request->getHeader('sec-websocket-key'))],
            ],
        ];
    }

    public function testBroadcast(): void
    {
        $client = $this->createMock(WebsocketClient::class);
        $client->method('getRemoteAddress')
            ->willReturn(new Socket\InternetAddress('127.0.0.1', 1));
        $client->expects($this->once())
            ->method('sendText')
            ->with('Text');
        $client->expects($this->once())
            ->method('sendBinary')
            ->with('Binary');
        $client->method('isClosed')
            ->willReturn(false);

        $this->execute(function (WebsocketGateway $gateway, WebsocketClient $client) {
            $gateway->broadcastText('Text')->await();
            $gateway->broadcastBinary('Binary')->await();
        }, $client);
    }

    public function testBroadcastExcept(): void
    {
        $client = $this->createMock(WebsocketClient::class);
        $client->method('getRemoteAddress')
            ->willReturn(new Socket\InternetAddress('127.0.0.1', 1));
        $client->expects($this->never())
            ->method('sendText');
        $client->expects($this->never())
            ->method('sendBinary');
        $client->method('isClosed')
            ->willReturn(false);

        $this->execute(function (WebsocketGateway $gateway, WebsocketClient $client) {
            $gateway->broadcastText('Text', [$client->getId()])->await();
            $gateway->broadcastBinary('Binary', [$client->getId()])->await();
        }, $client);
    }

    public function testMulticast(): void
    {
        $client = $this->createMock(WebsocketClient::class);
        $client->method('getRemoteAddress')
            ->willReturn(new Socket\InternetAddress('127.0.0.1', 1));
        $client->expects($this->once())
            ->method('sendText')
            ->with('Text');
        $client->expects($this->once())
            ->method('sendBinary')
            ->with('Binary');
        $client->method('isClosed')
            ->willReturn(false);

        $this->execute(function (WebsocketGateway $gateway, WebsocketClient $client) {
            $gateway->multicastText('Text', [$client->getId()])->await();
            $gateway->multicastBinary('Binary', [$client->getId()])->await();
        }, $client);
    }

    public function testBroadcastIntermixedWithSends(): void
    {
        $client = $this->createMock(WebsocketClient::class);
        $client->method('getRemoteAddress')
            ->willReturn(new Socket\InternetAddress('127.0.0.1', 1));
        $client->expects($this->exactly(5))
            ->method('sendText')
            ->withConsecutive(...\array_map(fn (int $index) => [(string) $index], \range(1, 5)))
            ->willReturnCallback(static fn () => delay(0.01));
        $client->method('isClosed')
            ->willReturn(false);

        $this->execute(function (WebsocketGateway $gateway, WebsocketClient $client) {
            Future\await([
                $gateway->broadcastText('1'),
                $gateway->sendText('2', $client->getId()),
                $gateway->broadcastText('3'),
                $gateway->sendText('4', $client->getId()),
                $gateway->broadcastText('5'),
            ]);
        }, $client);
    }
}
