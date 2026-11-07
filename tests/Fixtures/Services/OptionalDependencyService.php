<?php

namespace Tests\Fixtures\Services;

use SineMacula\ApiToolkit\Services\Service;

/**
 * Service whose handler only declares optional parameters.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
class OptionalDependencyService extends Service
{
    /** @var array{\Tests\Fixtures\Services\ServiceProbe|null, list<\Tests\Fixtures\Services\ServiceProbe>}|null The arguments the handler received */
    public ?array $received = null;

    /**
     * Handle the service.
     *
     * @param  \Tests\Fixtures\Services\ServiceProbe|null  $probe
     * @param  \Tests\Fixtures\Services\ServiceProbe  ...$others
     * @return bool
     */
    protected function handle(?ServiceProbe $probe = null, ServiceProbe ...$others): bool
    {
        $this->received = [$probe, array_values($others)];

        return true;
    }
}
