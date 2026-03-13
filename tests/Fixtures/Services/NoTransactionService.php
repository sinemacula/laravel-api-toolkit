<?php

namespace Tests\Fixtures\Services;

use SineMacula\ApiToolkit\Services\Service;

/**
 * Fixture service with transactions disabled.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class NoTransactionService extends Service
{
    /** @var bool Track whether success() was called */
    public bool $successCalled = false;

    /**
     * Method is triggered if the handle method ran successfully.
     *
     * @return void
     */
    #[\Override]
    public function success(): void
    {
        $this->successCalled = true;
    }

    /**
     * Return the ordered list of concern classes for this service.
     *
     * @return array<int, class-string<\SineMacula\ApiToolkit\Services\Contracts\ServiceConcern>>
     */
    #[\Override]
    protected function concerns(): array
    {
        return [];
    }

    /**
     * Handles the main execution of the service.
     *
     * @return bool
     */
    #[\Override]
    protected function handle(): bool
    {
        return true;
    }
}
