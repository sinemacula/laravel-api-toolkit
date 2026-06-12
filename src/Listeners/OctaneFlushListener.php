<?php

namespace SineMacula\ApiToolkit\Listeners;

use SineMacula\ApiToolkit\Cache\CacheManager;

/**
 * Flushes all toolkit caches after each Octane request.
 *
 * Prevents stale metadata from persisting across requests in long-running
 * Octane processes by delegating to the centralized CacheManager.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class OctaneFlushListener
{
    /**
     * Create a new octane flush listener instance.
     *
     * @param  \SineMacula\ApiToolkit\Cache\CacheManager  $cacheManager
     * @return void
     */
    public function __construct(

        /** The cache manager for flushing toolkit caches. */
        private readonly CacheManager $cacheManager,

    ) {}

    /**
     * Handle the event.
     *
     * The event parameter is unused but required by Laravel's event
     * listener signature.
     *
     * @SuppressWarnings("php:S1172")
     *
     * @param  object  $event
     * @return void
     */
    public function handle(object $event): void
    {
        $this->cacheManager->flush();
    }
}
