<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Concerns;

use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\App;
use SineMacula\ApiToolkit\Services\Jobs\ServiceJob;
use SineMacula\ApiToolkit\Services\ServiceContext;
use SineMacula\ApiToolkit\Services\ServiceRunner;

/**
 * Queues a service and relays the queue's terminal failure to it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait RunsOnQueue
{
    /**
     * Run authorize() and validate(), then queue the service.
     *
     * A refusal throws from here and nothing is queued. The worker runs both
     * again before handle().
     *
     * @return \Illuminate\Foundation\Bus\PendingDispatch
     */
    final public function dispatch(): PendingDispatch
    {
        $job    = $this->toJob();
        $class  = ServiceRunner::class;
        $runner = App::make($class);
        assert($runner instanceof ServiceRunner);

        $runner->preflight($this, $job->context);

        return new PendingDispatch($job);
    }

    /**
     * Build the queued job without the pre-flight, for a batch or a chain.
     *
     * @return \SineMacula\ApiToolkit\Services\Jobs\ServiceJob
     */
    final public function toJob(): ServiceJob
    {
        return ServiceJob::for($this, $this->input, $this->invocationContext());
    }

    /**
     * Return the closure that calls onFinalFailure().
     *
     * @internal
     *
     * @return \Closure(\Throwable): void
     */
    final public function finalFailureHook(): \Closure
    {
        return function (\Throwable $exception): void {
            $this->onFinalFailure($exception);
        };
    }

    /**
     * React to the queue giving up on the job.
     *
     * Runs when the queue fails the job: its attempts or exceptions run out, or
     * a refusal or a timeout ends it. Never for run() or runOrFail(). Laravel
     * deletes the job first, so it runs at most once, best effort, and must be
     * idempotent. Exceptions are reported. Default: no-op.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    protected function onFinalFailure(\Throwable $exception): void {}

    /**
     * Return the attached context, or an INTERNAL one for the actor.
     *
     * @return \SineMacula\ApiToolkit\Services\ServiceContext
     */
    abstract private function invocationContext(): ServiceContext;
}
