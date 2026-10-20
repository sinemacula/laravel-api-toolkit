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
use SineMacula\ApiToolkit\Events\WritePoolFlushFailed;
use SineMacula\ApiToolkit\Exceptions\FailureReporter;
use SineMacula\ApiToolkit\Exceptions\WritePoolFlushException;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;

/**
 * Flushes the deferred write pool at lifecycle boundaries.
 *
 * This subscriber listens to HTTP request, CLI command, and queue job lifecycle
 * events to ensure all buffered inserts are persisted before the process ends.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class WritePoolFlushSubscriber
{
    /**
     * Create a new write pool flush subscriber instance.
     *
     * @param  \Illuminate\Contracts\Container\Container  $container
     * @return void
     */
    public function __construct(

        /** The container used to resolve the write pool */
        private Container $container,
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
     * The pool is resolved from the container at event time to ensure the
     * correct scoped instance is used in Octane environments. The flush itself
     * invalidates the per-query cache for the tables it persists, so this
     * subscriber only escalates failures: when the flush returns a result with
     * failures, or raises a WritePoolFlushException under the throw strategy,
     * the failure is reported as a WritePoolFlushException and a
     * WritePoolFlushFailed event is dispatched. Under the log strategy the
     * failed writes are disposable and each chunk is already logged, so only
     * the event is dispatched. A raised exception is only re-thrown when the
     * rethrow_at_boundary config flag is enabled, in which case reporting is
     * left to the boundary that catches it. Any other throwable is unexpected
     * and is reported.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\WritePoolFlushException
     */
    public function handleFlush(): void
    {
        try {
            $flushResult = $this->container->make(WritePool::class)->flush();
        } catch (WritePoolFlushException $exception) {
            $this->escalateThrown($exception);

            return;
        } catch (\Throwable $e) {
            FailureReporter::report($e, 'WritePool flush subscriber failed');

            return;
        }

        if ($flushResult->isSuccessful()) {
            return;
        }

        $this->escalate(new WritePoolFlushException($flushResult, $flushResult->firstException()), $flushResult->droppedRecordCount() === 0);
    }

    /**
     * Escalate a WritePoolFlushException raised by the throw strategy,
     * re-throwing it when the rethrow_at_boundary config flag is enabled.
     *
     * @param  \SineMacula\ApiToolkit\Exceptions\WritePoolFlushException  $exception
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\WritePoolFlushException
     */
    private function escalateThrown(WritePoolFlushException $exception): void
    {
        $rethrow = (bool) Config::get('api-toolkit.deferred_writes.rethrow_at_boundary', false);

        $this->escalate($exception, !$rethrow);

        if ($rethrow) {
            throw $exception;
        }
    }

    /**
     * Escalate a flush failure by optionally reporting it and dispatching a
     * WritePoolFlushFailed event.
     *
     * Neither step can escape: a reporting failure falls back to a log line and
     * still lets the event dispatch, and a dispatch failure is reported.
     *
     * @param  \SineMacula\ApiToolkit\Exceptions\WritePoolFlushException  $exception
     * @param  bool  $report
     * @return void
     */
    private function escalate(WritePoolFlushException $exception, bool $report): void
    {
        if ($report) {
            FailureReporter::report($exception, 'WritePool boundary flush failed');
        }

        try {
            event(new WritePoolFlushFailed($exception->flushResult()));
        } catch (\Throwable $e) { // @phpstan-ignore catch.neverThrown
            FailureReporter::report($e, 'WritePool flush subscriber failed');
        }
    }
}
