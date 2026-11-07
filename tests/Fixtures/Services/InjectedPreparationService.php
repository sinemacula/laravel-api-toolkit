<?php

namespace Tests\Fixtures\Services;

use SineMacula\ApiToolkit\Services\Service;

/**
 * Service whose prepare hook declares its dependencies as parameters.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
class InjectedPreparationService extends Service
{
    /** @var \Tests\Fixtures\Services\ServiceLog|null The log the handler writes to */
    private ?ServiceLog $log = null;

    /**
     * Prepare the service for execution.
     *
     * @param  \Tests\Fixtures\Services\ServiceLog  $log
     * @param  \Tests\Fixtures\Services\ServiceProbe  $probe
     * @return void
     */
    public function prepare(ServiceLog $log, ServiceProbe $probe): void
    {
        $this->log = $log;

        $log->add('prepare:' . class_basename($probe));
    }

    /**
     * Handle the service.
     *
     * @return bool
     */
    protected function handle(): bool
    {
        $this->log?->add('handle');

        return true;
    }
}
