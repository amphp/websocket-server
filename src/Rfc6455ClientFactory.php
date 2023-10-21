<?php declare(strict_types=1);

namespace Amp\Websocket\Server;

use Amp\ByteStream\ResourceStream;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Socket\Socket;
use Amp\Websocket\Compression\WebsocketCompressionContext;
use Amp\Websocket\ConstantRateLimit;
use Amp\Websocket\Parser\Rfc6455ParserFactory;
use Amp\Websocket\Parser\WebsocketParserFactory;
use Amp\Websocket\PeriodicHeartbeatQueue;
use Amp\Websocket\Rfc6455Client;
use Amp\Websocket\WebsocketClient;
use Amp\Websocket\WebsocketHeartbeatQueue;
use Amp\Websocket\WebsocketRateLimit;

final class Rfc6455ClientFactory implements WebsocketClientFactory
{
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * @param WebsocketHeartbeatQueue|null $heartbeatQueue Use null to disable automatic heartbeats (pings).
     * @param WebsocketRateLimit|null $rateLimit Use null to disable client rate limits.
     */
    public function __construct(
        private readonly ?WebsocketHeartbeatQueue $heartbeatQueue = new PeriodicHeartbeatQueue(),
        private readonly ?WebsocketRateLimit $rateLimit = new ConstantRateLimit(),
        private readonly WebsocketParserFactory $parserFactory = new Rfc6455ParserFactory(),
        private readonly int $frameSplitThreshold = Rfc6455Client::DEFAULT_FRAME_SPLIT_THRESHOLD,
        private readonly float $closePeriod = Rfc6455Client::DEFAULT_CLOSE_PERIOD,
    ) {
    }

    public function createClient(
        Request $request,
        Response $response,
        Socket $socket,
        ?WebsocketCompressionContext $compressionContext,
    ): WebsocketClient {
        if ($socket instanceof ResourceStream) {
            $socketResource = $socket->getResource();

            // Setting via stream API doesn't work and TLS streams are not supported
            // once TLS is enabled
            $isNodelayChangeSupported = \is_resource($socketResource)
                && !isset(\stream_get_meta_data($socketResource)["crypto"])
                && \extension_loaded('sockets')
                && \defined('TCP_NODELAY');

            if ($isNodelayChangeSupported && ($sock = \socket_import_stream($socketResource))) {
                \set_error_handler(static fn () => true);
                try {
                    // error suppression for sockets which don't support the option
                    \socket_set_option($sock, \SOL_TCP, \TCP_NODELAY, 1);
                } finally {
                    \restore_error_handler();
                }
            }
        }

        return new Rfc6455Client(
            socket: $socket,
            masked: false,
            parserFactory: $this->parserFactory,
            compressionContext: $compressionContext,
            heartbeatQueue: $this->heartbeatQueue,
            rateLimit: $this->rateLimit,
            frameSplitThreshold: $this->frameSplitThreshold,
            closePeriod: $this->closePeriod,
        );
    }
}
