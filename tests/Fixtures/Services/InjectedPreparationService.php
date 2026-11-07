<?php

namespace Tests\Fixtures\Services;

use SineMacula\ApiToolkit\Services\InjectableService;
use Throwable;

/**
 * Injectable service whose prepare and handle hooks both declare their
 * dependencies as parameters.
 *
 * @extends \SineMacula\ApiToolkit\Services\InjectableService<\Illuminate\Support\Collection<string, mixed>>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
class InjectedPreparationService extends InjectableService
{
    /** @var \Throwable|null The exception passed to the failed hook */
    public ?Throwable $failure = null;

    /**
     * Prepare the service for execution.
     *
     * @param  \Tests\Fixtures\Services\ServiceLog  $log
     * @param  \Tests\Fixtures\Services\ServiceProbe  $probe
     * @return void
     *
     * @throws \Throwable
     */
    public function prepare(ServiceLog $log, ServiceProbe $probe): void
    {
        $log->add('prepare:' . class_basename($probe));

        $exception = $this->payload->get('prepare_exception');

        if ($exception instanceof Throwable) {
            throw $exception;
        }
    }

    /**
     * Method is triggered if the handle method failed.
     *
     * @param  Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        $this->failure = $exception;
    }

    /**
     * Handle the service.
     *
     * @param  \Tests\Fixtures\Services\ServiceLog  $log
     * @return bool
     */
    protected function handle(ServiceLog $log): bool
    {
        $log->add('handle');

        return true;
    }
}
