<?php

namespace Tests\Fixtures\Services;

use SineMacula\ApiToolkit\Services\Service;
use Throwable;

/**
 * Service whose handler declares its dependencies as parameters.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\Illuminate\Support\Collection<string, mixed>>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
class InjectedService extends Service
{
    /** @var \Tests\Fixtures\Services\ServiceProbe|null The probe the handler received */
    public ?ServiceProbe $probe = null;

    /**
     * Handle the service.
     *
     * @param  \Tests\Fixtures\Services\ServiceLog  $log
     * @param  \Tests\Fixtures\Services\ServiceProbe  $probe
     * @param  string  $label
     * @return bool
     *
     * @throws \Throwable
     */
    protected function handle(ServiceLog $log, ServiceProbe $probe, string $label = 'default'): bool
    {
        $this->probe = $probe;

        $log->add('handle:' . $label);

        $exception = $this->payload->get('exception');

        if ($exception instanceof Throwable) {
            throw $exception;
        }

        return (bool) $this->payload->get('result', true);
    }
}
