<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Concerns;

use SineMacula\ApiToolkit\Services\Contracts\ServiceConcern;
use SineMacula\ApiToolkit\Services\ServiceContext;

/**
 * Fixture concern that answers with a stored result without calling the rest of
 * the pipeline, as an idempotency concern replaying a request would.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ShortCircuitConcern implements ServiceConcern
{
    /**
     * Return the stored result.
     *
     * @SuppressWarnings("php:S1172")
     *
     * @param  \SineMacula\ApiToolkit\Services\ServiceContext  $context
     * @param  \Closure(): mixed  $next
     * @return string
     */
    #[\Override]
    public function handle(ServiceContext $context, \Closure $next): string
    {
        return 'stored';
    }
}
