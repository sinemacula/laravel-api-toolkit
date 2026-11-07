<?php

namespace Tests\Fixtures\Services;

use SineMacula\ApiToolkit\Services\InjectableService;

/**
 * Service whose handler is private and so cannot be called by the base class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
class PrivateHandlerService extends InjectableService
{
    /**
     * Handle the service.
     *
     * @SuppressWarnings("php:S1144")
     *
     * @return bool
     */
    private function handle(): bool
    {
        return true;
    }
}
