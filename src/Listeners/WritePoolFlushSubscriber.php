<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Listeners;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use SineMacula\ApiToolkit\Events\WritePoolFlushFailed;
use SineMacula\ApiToolkit\Exceptions\WritePoolFlushException;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult;

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

        /** The container used to resolve the write pool */
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
     * the correct scoped instance is used in Octane environments. When
     * the flush returns a result with failures, or raises a
     * WritePoolFlushException under the throw strategy, the failure is
     * escalated loudly: a warning is logged and a WritePoolFlushFailed
     * event is dispatched. The exception is only re-thrown when the
     * rethrow_at_boundary config flag is enabled, so the lifecycle
     * boundary is never hard-crashed by default. Any other throwable is
     * unexpected and logged at error level.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\WritePoolFlushException
     */
    public function handleFlush(): void
    {
        try {

            $flushResult = $this->container->make(WritePool::class)->flush();

            if ($flushResult->isSuccessful()) {
                return;
            }

            $this->escalate($flushResult);
        } catch (WritePoolFlushException $exception) {

            $this->escalate($exception->flushResult());

            if (Config::get('api-toolkit.deferred_writes.rethrow_at_boundary', false)) {
                throw $exception;
            }
        } catch (\Throwable $e) {

            Log::error('WritePool flush subscriber failed', ['error' => $e->getMessage(), 'exception' => $e]);
        }
    }

    /**
     * Escalate a flush failure with a warning log and a dispatched
     * WritePoolFlushFailed event.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult  $flushResult
     * @return void
     */
    private function escalate(WritePoolFlushResult $flushResult): void
    {
        Log::warning('WritePool flush completed with failures: ' . $flushResult->failureCount() . ' chunk(s) failed out of ' . $flushResult->totalCount() . ' total.', [
            'failure_count' => $flushResult->failureCount(),
            'total_count'   => $flushResult->totalCount(),
            'tables'        => array_keys($flushResult->failures()),
        ]);

        event(new WritePoolFlushFailed($flushResult));
    }
}
