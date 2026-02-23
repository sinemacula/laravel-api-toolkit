<?php

namespace Tests\Fixtures\Services;

use SineMacula\ApiToolkit\Services\Service;

/**
 * Fixture service that always fails.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class FailingService extends Service
{
    /** @var bool Track whether failed() was called */
    public bool $failedCalled = false;

    /** @var \Throwable|null The exception passed to the failed callback */
    public ?\Throwable $failedException = null;

    /**
     * Method is triggered if the handle method failed.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        $this->failedCalled    = true;
        $this->failedException = $exception;
    }

    /**
     * Handles the main execution of the service.
     *
     * @return bool
     */
    protected function handle(): bool
    {
        throw new \RuntimeException('Service execution failed');
    }
}
