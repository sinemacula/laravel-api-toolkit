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
 * Flushes the deferred write pool at request, command and job boundaries.
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
     * Flush the write pool and escalate any failures.
     *
     * The pool is resolved at event time to get Octane's scoped instance. A
     * flush exception is re-thrown only when rethrow_at_boundary is enabled.
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
     * Escalate an exception raised by the throw strategy, re-throwing it when
     * rethrow_at_boundary is enabled.
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
     * Optionally report a flush failure, then dispatch WritePoolFlushFailed.
     *
     * Neither step can throw.
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
