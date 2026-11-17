<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Pipeline;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\DB;
use SineMacula\ApiToolkit\Exceptions\FailureReporter;
use SineMacula\ApiToolkit\Services\Service;

/**
 * Pipeline stage holding the service's cache lock around the rest of the run.
 *
 * A successful run holds the lock until the outermost transaction commits or
 * rolls back, so no competing run reads the state before the write is visible.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class LockStage
{
    /**
     * Run $next while holding the service's cache lock.
     *
     * @param  \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Contracts\ServiceInput, mixed>  $service
     * @param  \Closure(): mixed  $next
     * @return mixed
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\LockUnavailableException
     * @throws \Throwable
     */
    public function wrap(Service $service, \Closure $next): mixed
    {
        $lock = $service->lock();

        try {
            $output = $next();
        } catch (\Throwable $exception) {
            $this->release($lock);

            throw $exception;
        }

        $release = function () use ($lock): void {
            $this->release($lock);
        };

        DB::afterCommit($release);
        DB::afterRollBack($release);

        return $output;
    }

    /**
     * Release the lock, reporting a failure instead of throwing it.
     *
     * @param  \Illuminate\Contracts\Cache\Lock  $lock
     * @return void
     */
    private function release(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (\Throwable $exception) {
            FailureReporter::report($exception, 'Service lock release threw an exception.');
        }
    }
}
