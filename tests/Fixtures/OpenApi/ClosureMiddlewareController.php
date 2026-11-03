<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use Illuminate\Routing\Controllers\HasMiddleware;

/**
 * Fixture controller declaring a closure middleware beside an auth guard.
 *
 * Used to prove that a closure in the resolved middleware is skipped while the
 * string entries beside it are still read.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ClosureMiddlewareController implements HasMiddleware
{
    /**
     * Declare the controller middleware.
     *
     * @return list<\Closure|string>
     */
    #[\Override]
    public static function middleware(): array
    {
        return [
            static fn (mixed $request, \Closure $next): mixed => $next($request),
            'auth:api',
        ];
    }

    /**
     * Show the resource.
     *
     * @return void
     */
    public function show(): void {}
}
