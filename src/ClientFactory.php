<?php

namespace Amp\Websocket\Server;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Socket\Socket;
use Amp\Websocket\Client;
use Amp\Websocket\CompressionContext;
use Amp\Websocket\Options;

interface ClientFactory
{
    /**
     * Creates a Client object based on the given Request.
     *
     * @param Request                 $request
     * @param Response                $response
     * @param Socket                  $socket
     * @param Options                 $options
     * @param CompressionContext|null $compressionContext
     *
     * @return Client
     */
    public function createClient(
        Request $request,
        Response $response,
        Socket $socket,
        Options $options,
        ?CompressionContext $compressionContext = null
    ): Client;
}
