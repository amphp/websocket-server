<?php declare(strict_types=1);

namespace Amp\Websocket\Server;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Socket\Socket;
use Amp\Websocket\Compression\WebsocketCompressionContext;
use Amp\Websocket\WebsocketClient;

/**
 * Creates {@see WebsocketClient} instances after the HTTP upgrade response has been sent.
 *
 * Implement this interface to customise client creation, e.g. to wrap or configure the underlying socket.
 * The default implementation is {@see Rfc6455ClientFactory}.
 */
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
