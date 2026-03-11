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

    /** @var bool Indicate whether to use database transactions for the service */
    protected bool $useTransaction = false;

    /**
     * Method is triggered if the handle method ran successfully.
     *
     * @return void
     */
    public function success(): void
    {
        $this->successCalled = true;
    }

    /**
     * Handles the main execution of the service.
     *
     * @return bool
     */
    protected function handle(): bool
    {
        return true;
    }
}
