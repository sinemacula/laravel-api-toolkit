<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Concerns;

use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\DetectsLostConnections;
use Illuminate\Support\Facades\DB;

/**
 * Detects whether the application has a transaction open on a connection, so a
 * deferred write auto-flush can wait for it, and whether a failure ended one.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class DeferredWriteTransactionDetector
{
    use DetectsLostConnections;

    /**
     * Create a new deferred write transaction detector instance.
     *
     * @param  (\Closure(): (\Illuminate\Database\DatabaseTransactionsManager|null))|null  $transactions
     * @return void
     */
    public function __construct(

        /** Resolves the current transaction tracker, so a test's wrapping transaction is not counted. */
        private ?\Closure $transactions = null,
    ) {}

    /**
     * Determine whether the application has a transaction open on the
     * connection.
     *
     * A transaction a test opens to wrap itself, which the tracker reports as
     * not applicable to callbacks, is not counted.
     *
     * @param  string  $connection
     * @return bool
     */
    public function hasOpenTransaction(string $connection): bool
    {
        try {
            $level = DB::connection($connection)->transactionLevel();
        } catch (\Throwable) { // @phpstan-ignore catch.neverThrown
            // An unresolvable connection holds no transaction to wait for
            return false;
        }

        $tracker = $this->transactions === null ? null : ($this->transactions)();

        if (!$tracker instanceof DatabaseTransactionsManager) {
            return $level > 0;
        }

        $onConnection = fn (DatabaseTransactionRecord $transaction): bool => $transaction->connection === $connection;
        $wrapping     = $tracker->getPendingTransactions()->filter($onConnection)->count() - $tracker->callbackApplicableTransactions()->filter($onConnection)->count();

        return $level > $wrapping;
    }

    /**
     * Determine whether a failure inside an open transaction ended it: a
     * concurrency error raised from a nested transaction, or a lost connection.
     *
     * @param  \Throwable  $exception
     * @return bool
     *
     * @phpstan-assert-if-true \PDOException|\ErrorException $exception
     */
    public function endsTransaction(\Throwable $exception): bool
    {
        // A dropped connection can surface as a PHP warning instead
        if (!$exception instanceof \PDOException && !$exception instanceof \ErrorException) {
            return false;
        }

        return $exception instanceof DeadlockException || $this->causedByLostConnection($exception);
    }
}
