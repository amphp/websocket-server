<?php

namespace Amp\Websocket\Server;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Socket\Socket;
use Amp\Websocket\CompressionContext;
use Amp\Websocket\WebsocketClient;

interface WebsocketClientFactory
{
    /**
     * Creates a Client object based on the given Request.
     *
     * @param Request $request
     * @param Response $response
     * @param Socket $socket
     * @param CompressionContext|null $compressionContext
     *
     * @return WebsocketClient
     */
    public function createClient(
        Request $request,
        Response $response,
        Socket $socket,
        ?CompressionContext $compressionContext = null,
    ): WebsocketClient;
}
