<?php

namespace Tests\Fixtures\Services;

use SineMacula\ApiToolkit\Services\InjectableService;

/**
 * Injectable service whose handler only declares an optional class-typed
 * parameter.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
class OptionalInjectionService extends InjectableService
{
    /** @var \Tests\Fixtures\Services\ServiceProbe|null The probe the handler received */
    public ?ServiceProbe $probe = null;

    /**
     * Handle the service.
     *
     * @param  \Tests\Fixtures\Services\ServiceProbe|null  $probe
     * @return bool
     */
    protected function handle(?ServiceProbe $probe = null): bool
    {
        $this->probe = $probe;

        return true;
    }
}
