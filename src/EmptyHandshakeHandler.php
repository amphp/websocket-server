<?php declare(strict_types=1);

namespace Amp\Websocket\Server;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;

final class EmptyHandshakeHandler implements WebsocketHandshakeHandler
{
    use ForbidCloning;
    use ForbidSerialization;

    public function handleHandshake(Request $request, Response $response): Response
    {
        return $response;
    }
}
