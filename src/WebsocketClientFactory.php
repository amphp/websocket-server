<?php

namespace Amp\Websocket\Server;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Socket\Socket;
use Amp\Websocket\Compression\CompressionContext;
use Amp\Websocket\WebsocketClient;

interface WebsocketClientFactory
{
    /**
     * Creates a Client object based on the given Request.
     */
    public function createClient(
        Request $request,
        Response $response,
        Socket $socket,
        ?CompressionContext $compressionContext = null,
    ): WebsocketClient;
}
