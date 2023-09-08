<?php declare(strict_types=1);

namespace Amp\Websocket\Server\Internal;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;

/** @internal */
final class UpgradeErrorHandler implements ErrorHandler
{
    use ForbidCloning;
    use ForbidSerialization;

    public function handleError(int $status, ?string $reason = null, ?Request $request = null): Response
    {
        return new Response($status, ['connection' => 'close']);
    }
}
