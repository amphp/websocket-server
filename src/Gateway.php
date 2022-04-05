<?php

namespace Amp\Websocket\Server;

use Amp\Future;
use Amp\Http\Server\ErrorHandler;
use Amp\Websocket\Client;
use Psr\Log\LoggerInterface as PsrLogger;

interface Gateway
{
    /**
     * Broadcast a UTF-8 text message to all clients (except those given in the optional array).
     *
     * @param string $data Data to send.
     * @param int[]  $exceptIds List of IDs to exclude from the broadcast.
     *
     * @return Future<array{array<int, \Throwable>, array<int, null>}> Completes once the message has been sent to all
     * clients. The completion value is an array containing two arrays: an array of exceptions indexed by client ID of
     * sends that failed and an array with keys corresponding to client IDs of successful sends.
     * Note it is generally undesirable to await this future in a coroutine.
     *
     * @see Future\settle() Completion array corresponds to the return of this function.
     */
    public function broadcast(string $data, array $exceptIds = []): Future;

    /**
     * Send a binary message to all clients (except those given in the optional array).
     *
     * @param string $data Data to send.
     * @param int[]  $exceptIds List of IDs to exclude from the broadcast.
     *
     * @return Future<array{array<int, \Throwable>, array<int, null>}> Completes once the message has been sent to all
     * clients. The completion value is an array containing two arrays: an array of exceptions indexed by client ID of
     * sends that failed and an array with keys corresponding to client IDs of successful sends.
     * Note it is generally undesirable to await this future in a coroutine.
     *
     * @see Future\settle() Completion array corresponds to the return of this function.
     */
    public function broadcastBinary(string $data, array $exceptIds = []): Future;

    /**
     * Send a UTF-8 text message to a set of clients.
     *
     * @param string $data Data to send.
     * @param int[]  $clientIds Array of client IDs.
     *
     * @return Future<array{array<int, \Throwable>, array<int, null>}> Completes once the message has been sent to all
     * clients. The completion value is an array containing two arrays: an array of exceptions indexed by client ID of
     * sends that failed and an array with keys corresponding to client IDs of successful sends.
     * Note it is generally undesirable to await this future in a coroutine.
     *
     * @see Future\settle() Completion array corresponds to the return of this function.
     */
    public function multicast(string $data, array $clientIds): Future;

    /**
     * Send a binary message to a set of clients.
     *
     * @param string $data Data to send.
     * @param int[]  $clientIds Array of client IDs.
     *
     * @return Future<array{array<int, \Throwable>, array<int, null>}> Completes once the message has been sent to all
     * clients. The completion value is an array containing two arrays: an array of exceptions indexed by client ID of
     * sends that failed and an array with keys corresponding to client IDs of successful sends.
     * Note it is generally undesirable to await this future in a coroutine.
     *
     * @see Future\settle() Completion array corresponds to the return of this function.
     */
    public function multicastBinary(string $data, array $clientIds): Future;

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
}
