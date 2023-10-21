<?php declare(strict_types=1);

namespace Amp\Websocket\Server;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Socket\Socket;
use Amp\Websocket\Compression\WebsocketCompressionContext;
use Amp\Websocket\WebsocketClient;

interface WebsocketClientFactory
{
    /**
     * Creates a {@see WebsocketClient} after the upgrade response has been sent to the client.
     */
    public function createClient(
        Request $request,
        Response $response,
        Socket $socket,
        ?WebsocketCompressionContext $compressionContext,
    ): WebsocketClient;
}
