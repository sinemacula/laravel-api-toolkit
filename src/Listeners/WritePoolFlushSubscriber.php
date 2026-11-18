<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Listeners;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobTimedOut;
use SineMacula\ApiToolkit\Config\LifecycleSettings;
use SineMacula\ApiToolkit\Events\WritePoolFlushFailed;
use SineMacula\ApiToolkit\Exceptions\FailureReporter;
use SineMacula\ApiToolkit\Exceptions\WritePoolFlushException;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;

/**
 * Flushes the deferred write pool at the end of every request, command and
 * queue job attempt.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class WritePoolFlushSubscriber
{
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
        $events->listen(JobAttempted::class, [$this, 'handleFlush']);
        $events->listen(JobTimedOut::class, [$this, 'handleFlush']);
    }

    /**
     * Flush the write pool and escalate any failures.
     *
     * The pool is resolved from the current container, which under Octane is
     * the request's sandbox. Every queue job attempt is flushed, including a
     * released or timed-out one. A flush exception is re-thrown only when
     * rethrow_at_boundary is enabled.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\WritePoolFlushException
     */
    public function handleFlush(): void
    {
        try {
            $flushResult = Container::getInstance()->make(WritePool::class)->flush();
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
        $rethrow = LifecycleSettings::shouldRethrowAtBoundary();

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
