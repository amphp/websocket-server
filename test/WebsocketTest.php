<?php

namespace Amp\Websocket\Server\Test;

use Amp\ByteStream;
use Amp\Http\Server\Driver\Client as HttpClient;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestBody;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Server;
use Amp\Http\Status;
use Amp\Loop;
use Amp\PHPUnit\TestCase;
use Amp\Socket;
use Amp\Websocket\Server\Websocket;
use League\Uri;
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
            $server = new Server(
                [$this->createMock(Socket\Server::class)],
                $this->createMock(RequestHandler::class),
                new NullLogger
            );

            $websocket = $this->getMockForAbstractClass(Websocket::class);
            \assert($websocket instanceof Websocket);

            $websocket->expects($status === Status::SWITCHING_PROTOCOLS ? $this->once() : $this->never())
                ->method('onHandshake')
                ->willReturnArgument(1);

            yield $websocket->onStart($server);

            /** @var Response $response */
            $response = yield $websocket->handleRequest($request);
            $this->assertEquals($expectedHeaders, \array_intersect_key($response->getHeaders(), $expectedHeaders));

            if ($status === Status::SWITCHING_PROTOCOLS) {
                $this->assertEmpty(yield ByteStream\buffer($response->getBody()));
            }

            yield $websocket->onStop($server);
        });
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
        $request = new Request($this->createMock(HttpClient::class), "GET", Uri\Http::createFromString("/"), $headers);
        $testCases[] = [$request, Status::SWITCHING_PROTOCOLS, [
            "upgrade" => ["websocket"],
            "connection" => ["upgrade"],
            "sec-websocket-accept" => ["HSmrc0sMlYUkAGmm5OPpG2HaGWk="],
        ]];

        // 1 ----- error conditions: Handshake with POST method ----------------------------------->
        $request = new Request($this->createMock(HttpClient::class), "POST", Uri\Http::createFromString("/"), $headers);
        $testCases[] = [$request, Status::METHOD_NOT_ALLOWED, ["allow" => ["GET"]]];

        // 2 ----- error conditions: Handshake with 1.0 protocol ---------------------------------->
        $request = new Request($this->createMock(HttpClient::class), "GET", Uri\Http::createFromString("/"), $headers, null, "1.0");
        $testCases[] = [$request, Status::HTTP_VERSION_NOT_SUPPORTED];

        // 3 ----- error conditions: Handshake with non-empty body -------------------------------->
        $body = new RequestBody(new ByteStream\InMemoryStream("Non-empty body"));
        $request = new Request($this->createMock(HttpClient::class), "GET", Uri\Http::createFromString("/"), $headers, $body);
        $testCases[] = [$request, Status::BAD_REQUEST];

        // 4 ----- error conditions: Upgrade: Websocket header required --------------------------->
        $invalidHeaders = $headers;
        $invalidHeaders["upgrade"] = ["no websocket!"];
        $request = new Request($this->createMock(HttpClient::class), "GET", Uri\Http::createFromString("/"), $invalidHeaders, $body);
        $testCases[] = [$request, Status::UPGRADE_REQUIRED];

        // 5 ----- error conditions: Connection: Upgrade header required -------------------------->
        $invalidHeaders = $headers;
        $invalidHeaders["connection"] = ["no upgrade!"];
        $request = new Request($this->createMock(HttpClient::class), "GET", Uri\Http::createFromString("/"), $invalidHeaders, $body);
        $testCases[] = [$request, Status::UPGRADE_REQUIRED];

        // 6 ----- error conditions: Sec-Websocket-Key header required ---------------------------->
        $invalidHeaders = $headers;
        unset($invalidHeaders["sec-websocket-key"]);
        $request = new Request($this->createMock(HttpClient::class), "GET", Uri\Http::createFromString("/"), $invalidHeaders, $body);
        $testCases[] = [$request, Status::BAD_REQUEST];

        // 7 ----- error conditions: Sec-Websocket-Version header must be 13 ---------------------->
        $invalidHeaders = $headers;
        $invalidHeaders["sec-websocket-version"] = ["12"];
        $request = new Request($this->createMock(HttpClient::class), "GET", Uri\Http::createFromString("/"), $invalidHeaders, $body);
        $testCases[] = [$request, Status::BAD_REQUEST];

        return $testCases;
    }
}
