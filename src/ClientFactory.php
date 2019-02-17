<?php

namespace Amp\Websocket\Server;

use Amp\Socket\Socket;
use Amp\Websocket\Client;
use Amp\Websocket\CompressionContext;

interface ClientFactory
{
    /**
     * @param Socket                  $socket
     * @param Options                 $options
     * @param CompressionContext|null $compressionContext
     *
     * @return Client
     */
    public function createClient(Socket $socket, Options $options, ?CompressionContext $compressionContext = null): Client;
}
