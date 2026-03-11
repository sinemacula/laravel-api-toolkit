<?php

namespace Tests\Fixtures\Services;

use Illuminate\Support\Collection;
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
     * Constructor.
     *
     * @param  array<string, mixed>|\Illuminate\Support\Collection<string, mixed>|\stdClass  $payload
     * @param  bool  $useTransaction
     * @param  bool  $useLock
     */
    public function __construct(array|Collection|\stdClass $payload = [], bool $useTransaction = true, bool $useLock = true)
    {
        parent::__construct($payload, $useTransaction, $useLock);
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
    protected function getLockId(): string
    {
        return 'lockable-test';
    }
}
