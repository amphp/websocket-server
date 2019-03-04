<?php

namespace Amp\Websocket\Server\Test;

use Amp\ByteStream;
use Amp\Http\Server\Driver\Client as HttpClient;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Server;
use Amp\Http\Status;
use Amp\Loop;
use Amp\PHPUnit\TestCase;
use Amp\Promise;
use Amp\Socket;
use Amp\Success;
use Amp\Websocket\Server\Websocket;
use League\Uri;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;

class WebsocketTest extends TestCase
{
    /**
     * @param Request $request Request initiating the handshake.
     * @param int     $status Expected status code.
     * @param array   $expectedHeaders Expected response headers.
     *
     * @dataProvider provideHandshakes
     */
    public function testHandshake(Request $request, int $status, array $expectedHeaders = []): void
    {
        Loop::run(function () use ($request, $status, $expectedHeaders) {
            $websocket = $this->createMockWebsocket();

            $websocket->expects($status === Status::SWITCHING_PROTOCOLS ? $this->once() : $this->never())
                ->method('onHandshake')
                ->willReturnCallback(function (Request $request, Response $response): Promise {
                    return new Success($response);
                });

            /** @var Response $response */
            $response = yield $websocket->handleRequest($request);
            $this->assertEquals($expectedHeaders, \array_intersect_key($response->getHeaders(), $expectedHeaders));

            if ($status === Status::SWITCHING_PROTOCOLS) {
                $this->assertEmpty(yield ByteStream\buffer($response->getBody()));
            }
        });
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

        return new Request($this->createMock(HttpClient::class), "GET", Uri\Http::createFromString("/"), $headers);
    }

    public function provideHandshakes(): array
    {
        $testCases = [];

        $headers = [
            "host" => ["localhost"],
            "sec-websocket-key" => ["x3JJHMbDL1EzLkh9GBhXDw=="],
            "sec-websocket-version" => ["13"],
            "upgrade" => ["websocket"],
            "connection" => ["upgrade"],
        ];

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

    /**
     * @return Websocket|MockObject
     */
    public function createMockWebsocket(): Websocket
    {
        $server = new Server(
            [$this->createMock(Socket\Server::class)],
            $this->createMock(RequestHandler::class),
            new NullLogger
        );

        $websocket = $this->getMockForAbstractClass(Websocket::class);
        \assert($websocket instanceof Websocket);

        $websocket->onStart($server);

        return $websocket;
    }

    public function testInvalidOnHandshake(): void
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage("onHandshake() must implement interface Amp\Promise, bool returned");

        Loop::run(function () {
            $websocket = $this->createMockWebsocket();

            $websocket->expects($this->once())
                ->method('onHandshake')
                ->willReturn(false);

            $response = yield $websocket->handleRequest($this->createRequest());
        });
    }
}
