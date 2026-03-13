<?php

namespace SineMacula\ApiToolkit\Services\Concerns;

use Illuminate\Support\Facades\DB;
use SineMacula\ApiToolkit\Services\Contracts\ServiceConcern;
use SineMacula\ApiToolkit\Services\Service;

/**
 * Database transaction concern.
 *
 * Wraps the service pipeline in a database transaction with configurable
 * retry attempts. The transaction commits on success and rolls back on
 * exception.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class TransactionConcern implements ServiceConcern
{
    /** @var int Default number of transaction retry attempts */
    private const int DEFAULT_RETRIES = 3;

    /**
     * Execute the concern around the service lifecycle.
     *
     * @param  \SineMacula\ApiToolkit\Services\Service  $service
     * @param  \Closure(): bool  $next
     * @return bool
     */
    public function execute(Service $service, \Closure $next): bool
    {
        return (bool) DB::transaction(fn () => $next(), self::DEFAULT_RETRIES);
    }
}
