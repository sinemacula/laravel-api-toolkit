<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Concerns;

use SineMacula\ApiToolkit\Services\Contracts\ServiceConcern;
use SineMacula\ApiToolkit\Services\ServiceContext;

/**
 * Fixture concern that wraps the output of the rest of the pipeline.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class WrappingConcern implements ServiceConcern
{
    /**
     * Return the output of the rest of the pipeline under a "wrapped" key.
     *
     * @SuppressWarnings("php:S1172")
     *
     * @param  \SineMacula\ApiToolkit\Services\ServiceContext  $context
     * @param  \Closure(): mixed  $next
     * @return array{wrapped: mixed}
     */
    #[\Override]
    public function handle(ServiceContext $context, \Closure $next): array
    {
        return ['wrapped' => $next()];
    }
}
