<?php

namespace Tests\Fixtures\Services;

use Illuminate\Support\Collection;
use SineMacula\ApiToolkit\Services\Service;

/**
 * Fixture service that always succeeds.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class SimpleService extends Service
{
    /** @var bool Track whether success() was called */
    public bool $successCalled = false;

    /**
     * Constructor.
     *
     * @param  array<string, mixed>|\Illuminate\Support\Collection<string, mixed>|\stdClass  $payload
     * @param  bool  $useTransaction
     * @param  bool  $useLock
     */
    public function __construct(array|Collection|\stdClass $payload = [], bool $useTransaction = true, bool $useLock = false)
    {
        parent::__construct($payload, $useTransaction, $useLock);
    }

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
