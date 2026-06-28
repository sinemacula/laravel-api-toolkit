<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Concerns;

use SineMacula\ApiToolkit\Services\Service;

/**
 * Cache-based atomic locking stage.
 *
 * Acquires a cache lock before executing the pipeline and releases it in
 * a finally block, ensuring the lock is always released even on exception.
 * The runner only invokes this stage for lockable services.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class LockConcern
{
    /**
     * Acquire the service's cache lock, run $next, release in finally.
     *
     * @param  \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Contracts\ServiceInput, mixed>  $service
     * @param  \Closure(): mixed  $next
     * @return mixed
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\LockUnavailableException
     */
    public function wrap(Service $service, \Closure $next): mixed
    {
        $service->lock();

        try {
            return $next();
        } finally {
            $service->unlock();
        }
    }
}
