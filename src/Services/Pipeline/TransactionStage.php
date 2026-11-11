<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Pipeline;

use Illuminate\Support\Facades\DB;

/**
 * Pipeline stage wrapping the rest of the run in a database transaction.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class TransactionStage
{
    /**
     * Run $next in a transaction, retrying up to $attempts times.
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
