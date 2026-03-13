<?php

namespace SineMacula\ApiToolkit\Services\Contracts;

use SineMacula\ApiToolkit\Services\Service;

/**
 * Contract for composable service concerns.
 *
 * Defines the middleware-style contract for cross-cutting concerns that
 * wrap the service lifecycle. Each concern receives the service instance
 * and a closure representing the next step in the pipeline.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface ServiceConcern
{
    /**
     * Execute the concern around the service lifecycle.
     *
     * The concern wraps the remaining pipeline by calling $next()
     * to continue execution, or returns a boolean directly to
     * short-circuit the pipeline.
     *
     * @param  \SineMacula\ApiToolkit\Services\Service  $service
     * @param  \Closure(): bool  $next
     * @return bool
     */
    public function execute(Service $service, \Closure $next): bool;
}
