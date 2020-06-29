<?php

namespace Amp\Websocket\Server;

use Amp\Http\Server\ErrorHandler;
use Amp\Promise;
use Amp\Websocket\Client;
use Amp\Websocket\Options;
use Psr\Log\LoggerInterface as PsrLogger;

interface Gateway
{
    /**
     * Broadcast a UTF-8 text message to all clients (except those given in the optional array).
     *
     * @param string $data Data to send.
     * @param int[]  $exceptIds List of IDs to exclude from the broadcast.
     *
     * @return Promise<array> Resolves once the message has been sent to all clients. Note it is
     *                        generally undesirable to yield this promise in a coroutine.
     */
    public function broadcast(string $data, array $exceptIds = []): Promise;

    /**
     * Send a binary message to all clients (except those given in the optional array).
     *
     * @param string $data Data to send.
     * @param int[]  $exceptIds List of IDs to exclude from the broadcast.
     *
     * @return Promise<array> Resolves once the message has been sent to all clients. Note it is
     *                        generally undesirable to yield this promise in a coroutine.
     */
    public function broadcastBinary(string $data, array $exceptIds = []): Promise;

    /**
     * Send a UTF-8 text message to a set of clients.
     *
     * @param string $data Data to send.
     * @param int[]  $clientIds Array of client IDs.
     *
     * @return Promise<array> Resolves once the message has been sent to all clients. Note it is
     *                        generally undesirable to yield this promise in a coroutine.
     */
    public function multicast(string $data, array $clientIds): Promise;

    /**
     * Send a binary message to a set of clients.
     *
     * @param string $data Data to send.
     * @param int[]  $clientIds Array of client IDs.
     *
     * @return Promise<array> Resolves once the message has been sent to all clients. Note it is
     *                        generally undesirable to yield this promise in a coroutine.
     */
    public function multicastBinary(string $data, array $clientIds): Promise;

    /**
     * @return Client[] Array of Client objects currently connected to this endpoint indexed by their IDs.
     */
    public function getClients(): array;

    /**
     * @return PsrLogger HTTP server logger.
     */
    public function getLogger(): PsrLogger;

    /**
     * @return ErrorHandler HTTP server error handler.
     */
    public function getErrorHandler(): ErrorHandler;

    /**
     * @return Options
     */
    public function getOptions(): Options;
}
