<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Contracts;

use SineMacula\ApiToolkit\Services\ServiceContext;

/**
 * Middleware-style concern wrapping the service lifecycle.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface ServiceConcern
{
    /**
     * Handle the concern, calling $next() to continue the pipeline.
     *
     * @param  \SineMacula\ApiToolkit\Services\ServiceContext  $context
     * @param  \Closure(): mixed  $next
     * @return mixed
     */
    public function handle(ServiceContext $context, \Closure $next): mixed;
}
