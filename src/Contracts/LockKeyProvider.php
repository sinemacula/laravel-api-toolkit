<?php

namespace SineMacula\ApiToolkit\Contracts;

/**
 * Contract for classes that provide their own lock key to the Lockable
 * trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface LockKeyProvider
{
    /**
     * Generate the key used for cache-based locking.
     *
     * @return string
     */
    public function getLockKey(): string;
}
