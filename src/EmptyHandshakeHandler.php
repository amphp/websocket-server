<?php

namespace Amp\Websocket\Server;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;

final class EmptyHandshakeHandler implements HandshakeHandler
{
    public function handleHandshake(Request $request, Response $response): Response
    {
        return $response;
    }
}
