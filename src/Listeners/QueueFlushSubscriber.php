<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Listeners;

use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Runtime\RuntimeContext;

/**
 * Flushes all toolkit caches after each queue job completes or fails.
 *
 * Prevents stale metadata from persisting across jobs in long-running
 * worker processes by delegating to the centralized CacheManager. When the
 * job was dispatched via the sync driver (i.e. within an HTTP request), the
 * flush is skipped entirely.
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
     * Flush all toolkit caches when running inside a real queue worker.
     *
     * Jobs dispatched via the sync driver run within the HTTP request and do
     * not constitute a worker boundary; those are skipped.
     *
     * @param  \Illuminate\Queue\Events\JobFailed|\Illuminate\Queue\Events\JobProcessed  $event
     * @return void
     */
    public function handleFlush(JobFailed|JobProcessed $event): void
    {
        if (!$this->runtime->isServingAsQueueWorker($event->connectionName)) {
            return;
        }

        $this->cacheManager->flush();
    }
}
