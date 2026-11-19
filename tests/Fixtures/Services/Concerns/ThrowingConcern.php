<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Concerns;

use SineMacula\ApiToolkit\Services\Contracts\ServiceConcern;
use SineMacula\ApiToolkit\Services\ServiceContext;
use Tests\Fixtures\Exceptions\ServiceExecutionException;

/**
 * Fixture concern that refuses the run after the rest of the pipeline wrote, as
 * a tenancy guard checking the result would.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ThrowingConcern implements ServiceConcern
{
    /**
     * Run the rest of the pipeline, then throw.
     *
     * @SuppressWarnings("php:S1172")
     *
     * @param  \SineMacula\ApiToolkit\Services\ServiceContext  $context
     * @param  \Closure(): mixed  $next
     * @return never
     *
     * @throws \Tests\Fixtures\Exceptions\ServiceExecutionException
     */
    #[\Override]
    public function handle(ServiceContext $context, \Closure $next): never
    {
        $next();

        throw new ServiceExecutionException('The concern refused the result.');
    }
}
