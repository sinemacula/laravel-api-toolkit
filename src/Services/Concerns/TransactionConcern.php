<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Database transaction stage.
 *
 * Wraps the service pipeline in a database transaction with configurable retry
 * attempts. The transaction commits on success and rolls back on exception.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class TransactionConcern
{
    /**
     * Wrap $next in DB::transaction with $attempts retries.
     *
     * @param  \Closure(): mixed  $next
     * @param  int  $attempts
     * @return mixed
     */
    public function wrap(\Closure $next, int $attempts): mixed
    {
        return DB::transaction(fn (): mixed => $next(), $attempts);
    }
}
