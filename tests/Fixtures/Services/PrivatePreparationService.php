<?php

namespace Tests\Fixtures\Services;

use SineMacula\ApiToolkit\Services\InjectableService;

/**
 * Service whose prepare hook is private and so cannot be called by the base
 * class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
class PrivatePreparationService extends InjectableService
{
    /**
     * Prepare the service for execution.
     *
     * @SuppressWarnings("php:S1144")
     *
     * @return void
     */
    private function prepare(): void
    {
    }

    /**
     * Handle the service.
     *
     * @return bool
     */
    protected function handle(): bool
    {
        return true;
    }
}
