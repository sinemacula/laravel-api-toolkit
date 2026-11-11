<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Listeners;

use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Exceptions\FailureReporter;
use SineMacula\ApiToolkit\Runtime\RuntimeContext;

/**
 * Resets the toolkit's in-process state after each queue job completes or
 * fails.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class QueueFlushSubscriber
{
    /**
     * Create a new queue flush subscriber instance.
     *
     * @param  \SineMacula\ApiToolkit\Cache\CacheManager  $cacheManager
     * @param  \SineMacula\ApiToolkit\Runtime\RuntimeContext  $runtime
     * @return void
     */
    public function __construct(

        /** The cache manager for flushing toolkit caches */
        private CacheManager $cacheManager,

        /** The runtime context for detecting the serving environment */
        private RuntimeContext $runtime,
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
     * Sync-driver jobs run inside the request, so they are not a boundary.
     *
     * @param  \Illuminate\Queue\Events\JobFailed|\Illuminate\Queue\Events\JobProcessed  $event
     * @return void
     */
    public function handleFlush(JobFailed|JobProcessed $event): void
    {
        if (!$this->runtime->isServingAsQueueWorker($event->connectionName)) {
            return;
        }

        try {
            $this->cacheManager->flush();
        } catch (\Throwable $exception) { // @phpstan-ignore catch.neverThrown
            // Never crash the worker
            FailureReporter::report($exception, 'Queue worker cache flush failed');
        }
    }
}
