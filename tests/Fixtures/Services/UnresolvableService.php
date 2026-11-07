<?php

namespace Tests\Fixtures\Services;

use SineMacula\ApiToolkit\Services\Contracts\ServiceInterface;
use SineMacula\ApiToolkit\Services\InjectableService;
use Throwable;

/**
 * Service whose handler depends on an interface the container cannot build.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
class UnresolvableService extends InjectableService
{
    /** @var \Throwable|null The exception passed to the failed hook */
    public ?Throwable $failure = null;

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
     * @param  \SineMacula\ApiToolkit\Services\Contracts\ServiceInterface  $service
     * @return bool
     */
    protected function handle(ServiceInterface $service): bool
    {
        return true;
    }
}
