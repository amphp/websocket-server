<?php

namespace Amp\Websocket\Server;

use Amp\Http\Server\HttpServer;
use Amp\Promise;

interface WebsocketObserver
{
    /**
     * Called when the HTTP server is started. If an instance of WebsocketObserver is attached to multiple
     * Websocket instances, this method will be called once for each instance.
     *
     * @param HttpServer $server
     * @param Gateway    $gateway
     *
     * @return Promise<void>
     */
    public function onStart(HttpServer $server, Gateway $gateway): Promise;

    /**
     * Called when the HTTP server is stopped. If an instance of WebsocketObserver is attached to multiple
     * Websocket instances, this method will be called once for each instance.
     *
     * @param HttpServer $server
     * @param Gateway    $gateway
     *
     * @return Promise<void>
     */
    public function onStop(HttpServer $server, Gateway $gateway): Promise;
}
