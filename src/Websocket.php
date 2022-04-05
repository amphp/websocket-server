<?php

namespace Amp\Websocket\Server;

use Amp\CompositeException;
use Amp\Future;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Websocket\CompressionContextFactory;
use function Amp\async;

final class Websocket implements RequestHandler
{
    private ClientGateway $gateway;

    private RequestHandler $upgradeHandler;

    private \SplObjectStorage $observers;

    /**
     * @param ClientHandler $clientHandler
     * @param CompressionContextFactory|null $compressionFactory
     * @param ClientFactory|null $clientFactory
     */
    public function __construct(
        HttpServer $httpServer,
        ClientHandler $clientHandler,
        ?CompressionContextFactory $compressionFactory = null,
        ?ClientFactory $clientFactory = null,
        ?RequestHandler $upgradeHandler = null,
    ) {
        $httpServer->onStart($this->onStart(...));
        $httpServer->onStop($this->onStop(...));

        $clientFactory ??= new Rfc6455ClientFactory();

        $this->gateway = new ClientGateway(
            $clientHandler,
            $clientFactory,
            $compressionFactory,
        );

        $this->observers = new \SplObjectStorage;

        if (!$upgradeHandler) {
            $upgradeHandler = new Rfc6455UpgradeHandler();
            $httpServer->onStart($upgradeHandler->onStart(...));
        }

        $this->upgradeHandler = $upgradeHandler;

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

    private function onStart(HttpServer $server): void
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

    private function onStop(HttpServer $server): void
    {
        $onStopFutures = [];
        foreach ($this->observers as $observer) {
            \assert($observer instanceof WebsocketServerObserver);
            $onStopFutures[] = async(fn () => $observer->onStop($server, $this->gateway));
        }

        [$exceptions] = Future\settle($onStopFutures);

        $this->gateway->onStop($server);

        if (!empty($exceptions)) {
            throw new CompositeException($exceptions, 'Websocket shutdown failed');
        }
    }
}
