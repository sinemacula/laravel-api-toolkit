<?php

namespace SineMacula\ApiToolkit\Listeners;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Log;
use SineMacula\ApiToolkit\Events\WritePoolFlushFailed;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;

/**
 * Flushes the deferred write pool at lifecycle boundaries.
 *
 * This subscriber listens to HTTP request, CLI command, and queue
 * job lifecycle events to ensure all buffered inserts are persisted
 * before the process ends.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class WritePoolFlushSubscriber
{
    /**
     * Create a new write pool flush subscriber instance.
     *
     * @param  \Illuminate\Contracts\Container\Container  $container
     * @return void
     */
    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * Register the listeners for the subscriber.
     *
     * @param  \Illuminate\Events\Dispatcher  $events
     * @return void
     */
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(RequestHandled::class, [$this, 'handleFlush']);
        $events->listen(CommandFinished::class, [$this, 'handleFlush']);
        $events->listen(JobProcessed::class, [$this, 'handleFlush']);
        $events->listen(JobFailed::class, [$this, 'handleFlush']);
    }

    /**
     * Flush the write pool and handle any failures.
     *
     * The pool is resolved from the container at event time to ensure
     * the correct scoped instance is used in Octane environments.
     * When the flush result indicates failures, a warning is logged
     * and a WritePoolFlushFailed event is dispatched. All exceptions
     * are caught to prevent lifecycle boundary disruption.
     *
     * @return void
     */
    public function handleFlush(): void
    {
        try {

            $flushResult = $this->container->make(WritePool::class)->flush();

            if ($flushResult->isSuccessful()) {
                return;
            }

            Log::warning('WritePool flush completed with failures: ' . $flushResult->failureCount() . ' chunk(s) failed out of ' . $flushResult->totalCount() . ' total.', [
                'failure_count' => $flushResult->failureCount(),
                'total_count'   => $flushResult->totalCount(),
                'tables'        => array_keys($flushResult->failures()),
            ]);

            event(new WritePoolFlushFailed($flushResult));
        } catch (\Throwable $e) {

            Log::error('WritePool flush subscriber failed', ['error' => $e->getMessage()]);
        }
    }
}
