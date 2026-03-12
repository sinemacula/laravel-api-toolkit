<?php

namespace SineMacula\ApiToolkit\Traits;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use SineMacula\ApiToolkit\Contracts\LockKeyProvider;
use SineMacula\ApiToolkit\Exceptions\TooManyRequestsException;

/**
 * Cache-based atomic locking.
 *
 * Provides lock acquisition and release using Laravel's cache lock
 * system. Classes using this trait should implement the LockKeyProvider
 * contract to supply a lock key. Override the $lockExpiration property
 * to customize the lock duration (default 60 seconds). This trait has
 * no dependency on the Service base class or any other base class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait Lockable
{
    /** @var int Default cache lock expiration in seconds */
    private const int DEFAULT_LOCK_EXPIRATION = 60;

    /** @var int Cache lock expiration in seconds */
    protected int $lockExpiration = self::DEFAULT_LOCK_EXPIRATION;

    /** @var \Illuminate\Contracts\Cache\Lock|null The atomic cache lock */
    private ?Lock $lock = null;

    /** @var string The key used for locking the task execution */
    private string $lockKey;

    /**
     * Lock the task execution.
     *
     * @return \Illuminate\Contracts\Cache\Lock
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\ApiException
     */
    public function lock(): Lock
    {
        $this->lock = Cache::lock($this->resolveLockKey(), $this->getLockExpiration());

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
    public function unlock(): void
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
        return $this->lockExpiration;
    }

    /**
     * Resolve the lock key.
     *
     * @return string
     *
     * @throws \RuntimeException
     */
    private function resolveLockKey(): string
    {
        return $this->lockKey ??= ($this instanceof LockKeyProvider
            ? $this->getLockKey()
            : throw new \RuntimeException('The lock key must be provided via the LockKeyProvider contract or by setting the $lockKey property'));
    }
}
