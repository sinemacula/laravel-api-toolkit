<?php

namespace Tests\Fixtures\Traits;

use Illuminate\Contracts\Cache\Lock;
use SineMacula\ApiToolkit\Contracts\LockKeyProvider;
use SineMacula\ApiToolkit\Traits\Lockable;

/**
 * Fixture class using Lockable without extending Service.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class StandaloneLockableFixture implements LockKeyProvider
{
    use Lockable;

    /**
     * Create a new standalone lockable fixture instance.
     *
     * @param  string  $lockId
     * @param  int  $lockExpiration
     */
    public function __construct(

        /** The identifier used when generating the cache lock key. */
        private readonly string $lockId,

        // Custom lock expiration in seconds
        int $lockExpiration = 60,

    ) {
        $this->lockExpiration = $lockExpiration;
    }

    /**
     * Acquire a cache lock.
     *
     * @return \Illuminate\Contracts\Cache\Lock
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\ApiException
     */
    public function acquireLock(): Lock
    {
        return $this->lock();
    }

    /**
     * Release the cache lock.
     *
     * @return void
     */
    public function releaseLock(): void
    {
        $this->unlock();
    }

    /**
     * Return the configured lock expiration in seconds.
     *
     * @return int
     */
    public function lockExpiration(): int
    {
        return $this->getLockExpiration();
    }

    /**
     * Generate the cache lock key.
     *
     * @return string
     */
    #[\Override]
    public function getLockKey(): string
    {
        return sha1(self::class . '|' . $this->lockId);
    }
}
