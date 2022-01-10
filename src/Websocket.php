<?php

namespace Amp\Websocket\Server;

use Amp\CompositeException;
use Amp\Future;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\ServerObserver;
use Amp\Http\Status;
use Amp\Websocket\CompressionContextFactory;
use Amp\Websocket\Options;
use Amp\Websocket\Rfc7692CompressionFactory;
use function Amp\async;

final class Websocket implements RequestHandler, ServerObserver
{
    private Rfc6455Gateway $gateway;

    private RequestHandler $upgradeHandler;

    private \SplObjectStorage $observers;

    /**
     * @param ClientHandler $clientHandler
     * @param Options|null $options
     * @param CompressionContextFactory|null $compressionFactory
     * @param ClientFactory|null $clientFactory
     */
    public function __construct(
        ClientHandler $clientHandler,
        ?Options $options = null,
        ?CompressionContextFactory $compressionFactory = null,
        ?ClientFactory $clientFactory = null,
        ?RequestHandler $upgradeHandler = null,
    ) {
        $clientFactory ??= new Rfc6455ClientFactory;
        $compressionFactory ??= new Rfc7692CompressionFactory;

        $this->gateway = new Rfc6455Gateway(
            $clientHandler,
            $options ?? Options::createServerDefault(),
            $clientFactory,
            $compressionFactory,
        );

        $this->observers = new \SplObjectStorage;
        $this->upgradeHandler = $upgradeHandler ?? new Rfc6455UpgradeHandler;

        if ($clientHandler instanceof WebsocketServerObserver) {
            $this->observers->attach($clientHandler);
        }

        if ($clientFactory instanceof WebsocketServerObserver) {
            $this->observers->attach($clientFactory);
        }

        if ($compressionFactory instanceof WebsocketServerObserver) {
            $this->observers->attach($compressionFactory);
        }
    }

    public function handleRequest(Request $request): Response
    {
        $response = $this->upgradeHandler->handleRequest($request);

        if ($response->getStatus() !== Status::SWITCHING_PROTOCOLS) {
            return $response;
        }

        return $this->gateway->handleHandshake($request, $response);
    }

    /**
     * Attaches a WebsocketObserver that is notified when the server starts and stops.
     *
     * @param WebsocketServerObserver $observer
     */
    public function attach(WebsocketServerObserver $observer): void
    {
        $this->observers->attach($observer);
    }

    /**
     * @inheritDoc
     */
    public function onStart(HttpServer $server): void
    {
        $this->upgradeHandler->onStart($server);
        $this->gateway->onStart($server);

        $onStartFutures = [];
        foreach ($this->observers as $observer) {
            \assert($observer instanceof WebsocketServerObserver);
            $onStartFutures[] = async(fn () => $observer->onStart($server, $this->gateway));
        }

        [$exceptions] = Future\settle($onStartFutures);

        if (!empty($exceptions)) {
            throw new CompositeException($exceptions, 'Websocket initialization failed');
        }
    }

    /**
     * @inheritDoc
     */
    public function onStop(HttpServer $server): void
    {
        $onStopFutures = [];
        foreach ($this->observers as $observer) {
            \assert($observer instanceof WebsocketServerObserver);
            $onStopFutures[] = async(fn () => $observer->onStop($server, $this->gateway));
        }

        [$exceptions] = Future\settle($onStopFutures);

        $this->upgradeHandler->onStop($server);
        $this->gateway->onStop($server);

        if (!empty($exceptions)) {
            throw new CompositeException($exceptions, 'Websocket shutdown failed');
        }
    }
}
