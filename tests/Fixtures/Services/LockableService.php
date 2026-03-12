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
    /**
     * Determine whether to lock the task execution.
     *
     * @return bool
     */
    #[\Override]
    protected function shouldUseLock(): bool
    {
        return true;
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

    /**
     * Return the unique id to be used in the generation of the lock key.
     *
     * @return string
     */
    #[\Override]
    protected function getLockId(): string
    {
        return 'lockable-test';
    }
}
