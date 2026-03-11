<?php

namespace SineMacula\ApiToolkit\Listeners;

use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use SineMacula\ApiToolkit\Cache\CacheManager;

/**
 * Flushes all toolkit caches after each queue job completes or fails.
 *
 * Prevents stale metadata from persisting across jobs in long-running
 * worker processes by delegating to the centralized CacheManager.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class QueueFlushSubscriber
{
    /**
     * Create a new queue flush subscriber instance.
     *
     * @param  \SineMacula\ApiToolkit\Cache\CacheManager  $cacheManager
     * @return void
     */
    public function __construct(

        /** The cache manager for flushing toolkit caches. */
        private readonly CacheManager $cacheManager,

    ) {}

    /**
     * Register the listeners for the subscriber.
     *
     * @param  \Illuminate\Events\Dispatcher  $events
     * @return void
     */
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(JobProcessed::class, [$this, 'handleFlush']);
        $events->listen(JobFailed::class, [$this, 'handleFlush']);
    }

    /**
     * Flush all toolkit caches.
     *
     * @return void
     */
    public function handleFlush(): void
    {
        $this->cacheManager->flush();
    }
}
