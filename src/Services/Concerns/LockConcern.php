<?php

namespace SineMacula\ApiToolkit\Services\Concerns;

use SineMacula\ApiToolkit\Services\Contracts\ServiceConcern;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Traits\Lockable;

/**
 * Cache-based atomic locking concern.
 *
 * Acquires a cache lock before executing the pipeline and releases it in
 * a finally block, ensuring the lock is always released even on exception.
 * Services must use the Lockable trait for this concern to take effect;
 * otherwise, execution passes through without locking.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class LockConcern implements ServiceConcern
{
    /**
     * Execute the concern around the service lifecycle.
     *
     * @param  \SineMacula\ApiToolkit\Services\Service  $service
     * @param  \Closure(): bool  $next
     * @return bool
     */
    public function execute(Service $service, \Closure $next): bool
    {
        if (!in_array(Lockable::class, class_uses_recursive($service), true)) {
            return $next();
        }

        $service->lock();

        try {
            return $next();
        } finally {
            $service->unlock();
        }
    }
}
