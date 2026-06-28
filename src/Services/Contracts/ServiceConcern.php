<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Contracts;

use SineMacula\ApiToolkit\Services\ServiceContext;

/**
 * Contract for composable service concerns.
 *
 * Defines the middleware-style contract for cross-cutting concerns that
 * wrap the service lifecycle. Each concern receives the immutable context
 * and a closure representing the next step in the pipeline.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface ServiceConcern
{
    /**
     * Handle the concern around the service lifecycle.
     *
     * The concern wraps the remaining pipeline by calling $next() to
     * continue execution, and may transform or short-circuit the result.
     *
     * @param  \SineMacula\ApiToolkit\Services\ServiceContext  $context
     * @param  \Closure(): mixed  $next
     * @return mixed
     */
    public function handle(ServiceContext $context, \Closure $next): mixed;
}
