<?php declare(strict_types=1);

namespace Amp\Websocket\Server;

use Amp\DeferredFuture;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\WebsocketClient;
use Amp\Websocket\WebsocketCloseCode;
use ColinODell\PsrTestLogger\TestLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use function Amp\Websocket\Client\connect;

class WebsocketIntegrationTest extends TestCase
{
    private TestLogger $logger;
    private SocketHttpServer $httpServer;
    private \Closure $clientHandler;

    protected function setUp(): void
    {
        $this->logger = new TestLogger();
        $this->httpServer = $this->createWebsocketServer(
            function (WebsocketGateway $gateway, WebsocketClient $client): void {
                ($this->clientHandler)($gateway, $client);
            }
        );
    }

    protected function tearDown(): void
    {
        $this->httpServer->stop();
    }

    /**
     * @param \Closure(WebsocketGateway, WebsocketClient):void $clientHandler
     */
    protected function createWebsocketServer(\Closure $clientHandler): SocketHttpServer
    {
        $httpServer = SocketHttpServer::createForDirectAccess(new NullLogger());
        $gateway = new WebsocketClientGateway();

        $websocket = new Websocket(
            httpServer: $httpServer,
            logger: $this->logger,
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
        );

        $httpServer->expose(new Socket\InternetAddress('127.0.0.1', 0));
        $httpServer->start($websocket, new DefaultErrorHandler());

        return $httpServer;
    }

    public function testCloseNotice(): void
    {
        $deferred = new DeferredFuture();

        $this->clientHandler = function (WebsocketGateway $gateway, WebsocketClient $client) use ($deferred): void {
            $test = $client->receive();

            $deferred->complete();
        };

        $connection = $this->connect();
        $connection->close(WebsocketCloseCode::PROTOCOL_ERROR);

        $deferred->getFuture()->await();

        self::assertTrue($this->logger->hasNoticeThatContains('Client initiated websocket close reporting error (code: 1002)'));
    }

    protected function connect(): WebsocketConnection
    {
        $address = $this->httpServer->getServers()[0] ?? self::fail('HTTP server did not create any socket servers');

        return connect('ws://' . $address->getAddress());
    }
}
