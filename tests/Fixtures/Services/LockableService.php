<?php

namespace Tests\Fixtures\Services;

use SineMacula\ApiToolkit\Services\Service;

/**
 * Fixture service with locking enabled.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class LockableService extends Service
{
    /** @var bool Indicate whether to lock the task execution */
    protected bool $useLock = true;

    /**
     * Handles the main execution of the service.
     *
     * @return bool
     */
    protected function handle(): bool
    {
        return true;
    }

    /**
     * Return the unique id to be used in the generation of the lock key.
     *
     * @return string
     */
    protected function getLockId(): string
    {
        return 'lockable-test';
    }
}
