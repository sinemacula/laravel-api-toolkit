<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Pipeline;

use SineMacula\ApiToolkit\Services\Service;

/**
 * Pipeline stage holding the service's cache lock around the rest of the run.
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
