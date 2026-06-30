<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Jobs;

use SineMacula\ApiToolkit\Services\Contracts\ServiceConcern;
use SineMacula\ApiToolkit\Services\ServiceContext;

/**
 * Concern fixture that captures the execution context for assertion.
 *
 * Stores the most recently received context in a static property so that tests
 * can assert which context was used to invoke the service pipeline.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ContextCapturingConcern implements ServiceConcern
{
    /** @var \SineMacula\ApiToolkit\Services\ServiceContext|null Last captured context */
    public static ?ServiceContext $captured = null;

    /**
     * Reset the captured context between tests.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$captured = null;
    }

    /**
     * Capture the context and pass control to the next stage.
     *
     * @param  \SineMacula\ApiToolkit\Services\ServiceContext  $context
     * @param  \Closure(): mixed  $next
     * @return mixed
     */
    #[\Override]
    public function handle(ServiceContext $context, \Closure $next): mixed
    {
        self::$captured = $context;

        return $next();
    }
}
