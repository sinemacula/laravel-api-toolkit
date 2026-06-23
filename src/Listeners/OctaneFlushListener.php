<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Listeners;

use Illuminate\Support\Facades\Log;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Runtime\RuntimeContext;

/**
 * Flushes all toolkit caches after each Octane request.
 *
 * Prevents stale metadata from persisting across requests in long-running
 * Octane processes by delegating to the centralized CacheManager. When not
 * serving under Octane (e.g. php-fpm), the flush is skipped entirely.
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
     * @param  \SineMacula\ApiToolkit\Runtime\RuntimeContext  $runtime
     * @return void
     */
    public function __construct(

        /** The cache manager for flushing toolkit caches. */
        private readonly CacheManager $cacheManager,

        /** The runtime context for detecting the serving environment. */
        private readonly RuntimeContext $runtime,

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
        if (!$this->runtime->isServingUnderOctane()) {
            return;
        }

        try {
            $this->cacheManager->flush();
        } catch (\Throwable $exception) {
            Log::error('Octane cache flush failed', ['exception' => $exception]);
        }
    }
}
