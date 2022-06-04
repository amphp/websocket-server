<?php

namespace Amp\Websocket\Server;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;

final class EmptyWebsocketHandshakeHandler implements WebsocketHandshakeHandler
{
    public function handleHandshake(Request $request, Response $response): Response
    {
        return $response;
    }
}
