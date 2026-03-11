<?php

namespace Tests\Fixtures\Traits;

use SineMacula\ApiToolkit\Traits\Lockable;

/**
 * Standalone consumer that uses the Lockable trait without extending
 * Service.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
class StandaloneLockConsumer
{
    use Lockable;

    /**
     * Create a new instance.
     *
     * @param  string  $lockId
     */
    public function __construct(

        /** The unique identifier used to generate the cache lock key. */
        private readonly string $lockId,

    ) {}

    /**
     * Generate the cache lock key.
     *
     * @return string
     */
    protected function generateLockKey(): string
    {
        return sha1(self::class . '|' . $this->lockId);
    }
}
