<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Listeners\Traits;

use Illuminate\Support\Facades\Cache;

/**
 * Provides an exclusive lock for event handling.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait ProvidesExclusiveLock
{
    /**
     * Executes the given callback under an exclusive lock.
     *
     * @param  string  $id
     * @param  callable(): void  $callback
     * @param  string  $prefix
     * @return void
     */
    protected function handleWithLock(string $id, callable $callback, string $prefix = 'LISTENER_LOCK'): void
    {
        $lock = Cache::lock(implode(':', array_filter([$prefix, $id], static fn (string $value): bool => (bool) $value)), 10);

        try {
            if ($lock->get()) {
                $callback();
            }
        } finally {
            $lock->release();
        }
    }
}
