<?php declare(strict_types=1);

namespace Amp\Websocket\Server;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Socket\Socket;
use Amp\Websocket\CompressionContext;
use Amp\Websocket\DefaultHeartbeatQueue;
use Amp\Websocket\DefaultRateLimiter;
use Amp\Websocket\HeartbeatQueue;
use Amp\Websocket\RateLimiter;
use Amp\Websocket\Rfc6455Client;
use Amp\Websocket\WebsocketClient;

final class Rfc6455ClientFactory implements WebsocketClientFactory
{
    public function __construct(
        private readonly ?HeartbeatQueue $heartbeatQueue = new DefaultHeartbeatQueue(),
        private readonly ?RateLimiter $rateLimiter = new DefaultRateLimiter(),
        private readonly int $frameSplitThreshold = Rfc6455Client::DEFAULT_FRAME_SPLIT_THRESHOLD,
        private readonly float $closePeriod = Rfc6455Client::DEFAULT_CLOSE_PERIOD,
    ) {
    }

    public function createClient(
        Request $request,
        Response $response,
        Socket $socket,
        ?CompressionContext $compressionContext = null,
    ): WebsocketClient {
        return new Rfc6455Client(
            socket: $socket,
            masked: false,
            compressionContext: $compressionContext,
            heartbeatQueue: $this->heartbeatQueue,
            rateLimiter: $this->rateLimiter,
            frameSplitThreshold: $this->frameSplitThreshold,
            closePeriod: $this->closePeriod,
        );
    }
}
