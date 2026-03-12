<?php

namespace Tests\Fixtures\Services;

use SineMacula\ApiToolkit\Services\Concerns\LockConcern;
use SineMacula\ApiToolkit\Services\Concerns\TransactionConcern;
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
     * Return the ordered list of concern classes for this service.
     *
     * @return array<int, class-string<\SineMacula\ApiToolkit\Services\Contracts\ServiceConcern>>
     */
    #[\Override]
    protected function concerns(): array
    {
        return [LockConcern::class, TransactionConcern::class];
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
