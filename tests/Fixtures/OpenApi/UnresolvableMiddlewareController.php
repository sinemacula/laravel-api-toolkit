<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use Illuminate\Routing\Controllers\HasMiddleware;

/**
 * Fixture controller whose middleware list cannot be resolved.
 *
 * Used to prove that a controller which throws while declaring its middleware
 * leaves the route documented as public rather than aborting the document
 * build.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class UnresolvableMiddlewareController implements HasMiddleware
{
    /**
     * Declare the controller middleware.
     *
     * @return list<string>
     *
     * @throws \RuntimeException
     */
    #[\Override]
    public static function middleware(): array
    {
        throw new \RuntimeException('The controller middleware cannot be resolved.');
    }

    /**
     * Show the resource.
     *
     * @return void
     */
    public function show(): void {}
}
