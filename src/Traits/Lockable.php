<?php

namespace SineMacula\ApiToolkit\Traits;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use SineMacula\ApiToolkit\Exceptions\TooManyRequestsException;

/**
 * The lockable trait.
 *
 * This trait provides helper methods to lock/unlock the execution of a given
 * script.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait Lockable
{
    /** @var int Default cache lock expiration in seconds */
    private const int DEFAULT_LOCK_EXPIRATION = 60;

    /** @var \Illuminate\Contracts\Cache\Lock|null The atomic cache lock */
    private ?Lock $lock = null;

    /** @var string|null The key used for locking the task execution */
    private ?string $lockKey = null;

    /**
     * Lock the task execution.
     *
     * @return \Illuminate\Contracts\Cache\Lock
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\ApiException
     */
    protected function lock(): Lock
    {
        $this->lock = Cache::lock($this->getLockKey(), $this->getLockExpiration());

        if (!$this->lock->get()) {
            throw new TooManyRequestsException(meta: ['X-RateLimit-Limit' => 1, 'X-RateLimit-Remaining' => 0]);
        }

        return $this->lock;
    }

    /**
     * Unlock the task execution.
     *
     * @return void
     */
    protected function unlock(): void
    {
        $this->lock?->release();
    }

    /**
     * Get the lock expiration.
     *
     * @return int
     */
    protected function getLockExpiration(): int
    {
        return self::DEFAULT_LOCK_EXPIRATION;
    }

    /**
     * Generate the key used for locking the task execution.
     *
     * @return string
     */
    abstract protected function generateLockKey(): string;

    /**
     * Get the lock key.
     *
     * @return string
     */
    private function getLockKey(): string
    {
        return $this->lockKey ??= $this->generateLockKey();
    }
}
