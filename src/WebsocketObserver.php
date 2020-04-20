<?php

namespace Amp\Websocket\Server;

use Amp\Promise;

interface WebsocketObserver
{
    /**
     * Called when the HTTP server is started. If an instance of ClientHandler is given to multiple
     * Websocket instances, this method will be called once for each instance.
     *
     * @param Endpoint $endpoint
     *
     * @return Promise<void>
     */
    public function onStart(Endpoint $endpoint): Promise;

    /**
     * Called when the HTTP server is stopped. If an instance of ClientHandler is given to multiple
     * Websocket instances, this method will be called once for each instance.
     *
     * @param Endpoint $endpoint
     *
     * @return Promise<void>
     */
    public function onStop(Endpoint $endpoint): Promise;
}
