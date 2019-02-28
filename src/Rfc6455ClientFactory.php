<?php

namespace Amp\Websocket\Server;

use Amp\Http\Server\Request;
use Amp\Socket\Socket;
use Amp\Websocket\Client;
use Amp\Websocket\CompressionContext;
use Amp\Websocket\Rfc6455Client;

final class Rfc6455ClientFactory implements ClientFactory
{
    public function createClient(
        Request $request,
        Socket $socket,
        Options $options,
        ?CompressionContext $compressionContext = null
    ): Client {
        return new Rfc6455Client($socket, $options, false, $compressionContext);
    }
}
