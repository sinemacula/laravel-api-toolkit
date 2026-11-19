<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Concerns;

use SineMacula\ApiToolkit\Services\Contracts\ServiceConcern;
use SineMacula\ApiToolkit\Services\ServiceContext;

/**
 * Fixture concern that captures the context it runs with.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ContextCapturingConcern implements ServiceConcern
{
    /** @var \SineMacula\ApiToolkit\Services\ServiceContext|null The context the concern ran with */
    public ?ServiceContext $context = null;

    /**
     * Capture the context and run the rest of the pipeline.
     *
     * @param  \SineMacula\ApiToolkit\Services\ServiceContext  $context
     * @param  \Closure(): mixed  $next
     * @return mixed
     */
    #[\Override]
    public function handle(ServiceContext $context, \Closure $next): mixed
    {
        $this->context = $context;

        return $next();
    }
}
