<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface;

use function React\Async\async;

/**
 * Run each HTTP handler in a Fiber so Neuron's await()-based React client
 * yields to the event loop instead of blocking other requests.
 */
final class FiberHandler
{
    public function __invoke(ServerRequestInterface $request, callable $next)
    {
        return async(static fn () => $next($request))();
    }
}
