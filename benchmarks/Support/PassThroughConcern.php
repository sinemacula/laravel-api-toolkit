<?php

declare(strict_types = 1);

namespace Benchmarks\Support;

use SineMacula\ApiToolkit\Services\Contracts\ServiceConcern;
use SineMacula\ApiToolkit\Services\ServiceContext;

/**
 * Concern that only continues the pipeline.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PassThroughConcern implements ServiceConcern
{
    /**
     * Continue the pipeline.
     *
     * @param  \SineMacula\ApiToolkit\Services\ServiceContext  $context
     * @param  \Closure(): mixed  $next
     * @return mixed
     */
    #[\Override]
    public function handle(ServiceContext $context, \Closure $next): mixed
    {
        return $next();
    }
}
