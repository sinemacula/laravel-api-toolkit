<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SineMacula\ApiToolkit\Config\LifecycleSettings;
use SineMacula\ApiToolkit\Enums\FlushStrategy;
use SineMacula\ApiToolkit\Exceptions\FailureReporter;
use SineMacula\ApiToolkit\Exceptions\WritePoolFlushException;

/**
 * Buffers inserts in memory by connection and table, and flushes them as
 * chunked bulk INSERTs.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class WritePool
{
    /** @var array<string, array<string, list<array<string, mixed>>>> Records buffered by connection, then table */
    private array $buffer = [];

    /** @var array<string, int> Records added to each connection since its last flush, which count toward the pool limit */
    private array $added = [];

    /** @var \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult|null Outcome of the most recent auto-flush */
    private ?WritePoolFlushResult $lastAutoFlushResult = null;

    /**
     * Create a new write pool instance.
     *
     * @param  int  $chunkSize
     * @param  int  $poolLimit
     * @param  \SineMacula\ApiToolkit\Enums\FlushStrategy  $strategy
     * @param  bool  $transactional
     * @param  (\Closure(): (\Illuminate\Database\DatabaseTransactionsManager|null))|null  $transactions
     * @return void
     */
    public function __construct(

        /** The maximum number of records inserted per chunk on flush. */
        private readonly int $chunkSize,

        /** The buffered record count at which an auto-flush triggers. */
        private readonly int $poolLimit,

        /** The strategy controlling how and when the buffer is flushed. */
        private readonly FlushStrategy $strategy = FlushStrategy::COLLECT,

        /** Whether each flush runs inside a database transaction. */
        private readonly bool $transactional = false,

        /** Resolves the current transaction tracker, so a test's wrapping transaction is not counted. */
        private readonly ?\Closure $transactions = null,
    ) {}

    /**
     * Add an attribute array to the buffer for the given connection and table.
     *
     * Reaching the pool limit with records added since each connection's last
     * flush auto-flushes every connection holding such records and no open
     * transaction, which may throw.
     *
     * @param  string  $connection
     * @param  string  $table
     * @param  array<string, mixed>  $attributes
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\WritePoolFlushException
     */
    public function add(string $connection, string $table, array $attributes): void
    {
        $this->buffer[$connection][$table][] = $attributes;
        $this->added[$connection]            = ($this->added[$connection] ?? 0) + 1;

        if (array_sum($this->added) < $this->poolLimit) {
            return;
        }

        $detector = new DeferredWriteTransactionDetector($this->transactions);
        $idle     = array_filter(array_keys($this->buffer), fn (string $name): bool => isset($this->added[$name]) && !$detector->hasOpenTransaction($name));

        if ($idle === []) {
            return;
        }

        // Retained so a non-throwing auto-flush failure stays observable
        $this->lastAutoFlushResult = $this->flushConnections($idle, $this->strategy);
    }

    /**
     * Flush all buffered records as chunked bulk INSERT statements.
     *
     * @param  \SineMacula\ApiToolkit\Enums\FlushStrategy|null  $strategy
     * @return \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\WritePoolFlushException
     */
    public function flush(?FlushStrategy $strategy = null): WritePoolFlushResult
    {
        return $this->flushConnections(array_keys($this->buffer), $strategy ?? $this->strategy);
    }

    /**
     * Return the outcome of the most recent auto-flush, or null if none ran.
     *
     * The only in-process signal that a non-throwing auto-flush failed.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult|null
     */
    public function lastAutoFlushResult(): ?WritePoolFlushResult
    {
        return $this->lastAutoFlushResult;
    }

    /**
     * Get the total number of buffered records across all connections.
     *
     * @return int
     */
    public function count(): int
    {
        $count = 0;

        foreach ($this->buffer as $tables) {
            foreach ($tables as $records) {
                $count += count($records);
            }
        }

        return $count;
    }

    /**
     * Determine whether the buffer is empty.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    /**
     * Flush the buffered records of the given connections and invalidate the
     * per-query cache for the tables the flush targeted.
     *
     * @param  array<int, string>  $connections
     * @param  \SineMacula\ApiToolkit\Enums\FlushStrategy  $strategy
     * @return \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\WritePoolFlushException
     */
    private function flushConnections(array $connections, FlushStrategy $strategy): WritePoolFlushResult
    {
        try {
            $result = $this->executeFlush($strategy, $this->targets($connections));
        } catch (WritePoolFlushException $exception) {
            $this->invalidateFlushedTables($exception->flushResult()->flushedTables());

            throw $exception;
        } finally {
            $this->added = array_diff_key($this->added, array_flip($connections));
        }

        $this->invalidateFlushedTables($result->flushedTables());

        return $result;
    }

    /**
     * Invalidate the per-query cache for a flush's target tables.
     *
     * @param  array<int, string>  $tables
     * @return void
     */
    private function invalidateFlushedTables(array $tables): void
    {
        if (!LifecycleSettings::shouldInvalidateQueryCache()) {
            return;
        }

        (new DeferredWriteCacheInvalidator)->invalidate($tables);
    }

    /**
     * List the buffered connection and table pairs of the given connections.
     *
     * @param  array<int, string>  $connections
     * @return list<array{string, string}>
     */
    private function targets(array $connections): array
    {
        $targets = [];

        foreach ($connections as $connection) {
            foreach (array_keys($this->buffer[$connection]) as $table) {
                $targets[] = [$connection, $table];
            }
        }

        return $targets;
    }

    /**
     * Execute the flush operation for the given targets with the given
     * strategy.
     *
     * @param  \SineMacula\ApiToolkit\Enums\FlushStrategy  $strategy
     * @param  list<array{string, string}>  $targets
     * @return \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\WritePoolFlushException
     */
    private function executeFlush(FlushStrategy $strategy, array $targets): WritePoolFlushResult
    {
        $accumulator = new WritePoolFlushAccumulator;

        foreach ($targets as $targetIndex => [$connection, $table]) {

            $chunks  = (new DeferredWriteChunker($this->chunkSize))->chunk($this->buffer[$connection][$table]);
            $context = new WritePoolFlushContext($strategy, $connection, $table, $chunks, $targets, $targetIndex);

            if ($this->transactional) {
                $this->flushTargetTransactionally($context, $accumulator);
            } else {
                $this->flushChunks($context, $accumulator);
            }
        }

        $this->settle($strategy, $targets, $accumulator);

        return $accumulator->toResult($strategy, $this->tableNames($targets));
    }

    /**
     * Remove the given flushed targets' records from the buffer, keeping the
     * failed records a collecting flush retains.
     *
     * @param  \SineMacula\ApiToolkit\Enums\FlushStrategy  $strategy
     * @param  list<array{string, string}>  $targets
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushAccumulator  $accumulator
     * @return void
     */
    private function settle(FlushStrategy $strategy, array $targets, WritePoolFlushAccumulator $accumulator): void
    {
        $retained = $strategy === FlushStrategy::COLLECT ? $accumulator->failedRecords() : [];

        foreach ($targets as [$connection, $table]) {
            $this->replace($connection, $table, $retained[$connection][$table] ?? []);
        }
    }

    /**
     * Get the distinct table names of the given targets.
     *
     * @param  list<array{string, string}>  $targets
     * @return array<int, string>
     */
    private function tableNames(array $targets): array
    {
        return array_values(array_unique(array_column($targets, 1)));
    }

    /**
     * Replace a target's buffered records, dropping the target when none are
     * left.
     *
     * @param  string  $connection
     * @param  string  $table
     * @param  list<array<string, mixed>>  $records
     * @return void
     */
    private function replace(string $connection, string $table, array $records): void
    {
        if ($records !== []) {
            $this->buffer[$connection][$table] = $records;

            return;
        }

        unset($this->buffer[$connection][$table]);

        if ($this->buffer[$connection] !== []) {
            return;
        }

        unset($this->buffer[$connection]);
    }

    /**
     * Flush a target's chunks all-or-nothing inside a transaction on the
     * target's connection.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushContext  $context
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushAccumulator  $accumulator
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\WritePoolFlushException
     */
    private function flushTargetTransactionally(WritePoolFlushContext $context, WritePoolFlushAccumulator $accumulator): void
    {
        $depth = 0;

        try {
            $connection = DB::connection($context->connection());
            $depth      = $connection->transactionLevel();

            $connection->transaction(function () use ($connection, $context): void {

                foreach ($context->chunks() as $chunk) {
                    $connection->table($context->table())->insert($chunk);
                }
            });

            foreach ($context->chunks() as $chunk) {
                $accumulator->recordSuccess($chunk);
            }
        } catch (\Throwable $exception) {
            $this->rethrowIfTheTransactionEnded($context, $accumulator, $depth, $exception);
            $this->handleTargetRollback($context, $accumulator, $exception);
        }
    }

    /**
     * Treat a rolled-back target's records as a single failure for the
     * strategy.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushContext  $context
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushAccumulator  $accumulator
     * @param  \Throwable  $exception
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\WritePoolFlushException
     */
    private function handleTargetRollback(WritePoolFlushContext $context, WritePoolFlushAccumulator $accumulator, \Throwable $exception): void
    {

        $records = array_merge(...$context->chunks());

        $accumulator->recordFailure($context->connection(), $context->table(), $records, $exception);

        if ($context->strategy() === FlushStrategy::THROW) {
            // Whole-target retain: the failed target keeps its merged records.
            $retained = $this->retainBuffer($context, $records);

            throw new WritePoolFlushException($accumulator->toThrowResult($retained, $this->tableNames($context->targets())), $exception);
        }

        if ($context->strategy() === FlushStrategy::LOG) {
            $this->logChunkFailure($context, $records, $exception);
        } else {
            $accumulator->retain($context->connection(), $context->table(), $records);
        }
    }

    /**
     * Insert each chunk of a target, in its own savepoint inside an open
     * transaction, accumulating the outcome.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushContext  $context
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushAccumulator  $accumulator
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\WritePoolFlushException
     *
     * @phpstan-ignore throws.unusedType
     */
    private function flushChunks(WritePoolFlushContext $context, WritePoolFlushAccumulator $accumulator): void
    {
        foreach ($context->chunks() as $chunkIndex => $chunk) {

            $depth = 0;

            try {
                $connection = DB::connection($context->connection());
                $depth      = $connection->transactionLevel();
                $insert     = fn (): bool => $connection->table($context->table())->insert($chunk);

                // A failed statement aborts an enclosing PostgreSQL transaction
                $depth > 0 ? $connection->transaction($insert) : $insert();

                $accumulator->recordSuccess($chunk);
            } catch (\Throwable $exception) { // @phpstan-ignore catch.neverThrown
                $this->rethrowIfTheTransactionEnded($context, $accumulator, $depth, $exception);
                $this->handleChunkFailure($context->withChunkIndex($chunkIndex), $accumulator, $chunk, $exception);
            }
        }
    }

    /**
     * Rethrow a failure that ended the caller's transaction, dropping every
     * record buffered for the connection, since a retry defers its own again.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushContext  $context
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushAccumulator  $accumulator
     * @param  int  $depth
     * @param  \Throwable  $exception
     * @return void
     *
     * @throws \PDOException
     * @throws \ErrorException
     */
    private function rethrowIfTheTransactionEnded(WritePoolFlushContext $context, WritePoolFlushAccumulator $accumulator, int $depth, \Throwable $exception): void
    {
        if ($depth === 0 || !(new DeferredWriteTransactionDetector)->endsTransaction($exception)) {
            return;
        }

        $this->settle($context->strategy(), array_slice($context->targets(), 0, $context->targetIndex()), $accumulator);

        unset($this->buffer[$context->connection()]);

        try {
            $this->invalidateFlushedTables($this->tableNames($context->targets()));
        } catch (\Throwable $failure) { // @phpstan-ignore catch.neverThrown
            // A cache failure must not replace the error the caller retries on
            FailureReporter::report($failure, 'Deferred write cache invalidation failed');
        }

        throw $exception;
    }

    /**
     * Handle a single chunk insertion failure for the active strategy.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushContext  $context
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushAccumulator  $accumulator
     * @param  list<array<string, mixed>>  $chunk
     * @param  \Throwable  $exception
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\WritePoolFlushException
     *
     * @phpstan-ignore method.unused
     */
    private function handleChunkFailure(WritePoolFlushContext $context, WritePoolFlushAccumulator $accumulator, array $chunk, \Throwable $exception): void
    {

        $accumulator->recordFailure($context->connection(), $context->table(), $chunk, $exception);

        if ($context->strategy() === FlushStrategy::THROW) {
            // Per-chunk mirror of handleTargetRollback()'s whole-target retain
            $unprocessed = array_slice($context->chunks(), (int) $context->chunkIndex() + 1);
            $retained    = $this->retainBuffer($context, array_merge($chunk, ...$unprocessed));

            throw new WritePoolFlushException($accumulator->toThrowResult($retained, $this->tableNames($context->targets())), $exception);
        }

        if ($context->strategy() === FlushStrategy::LOG) {
            $this->logChunkFailure($context, $chunk, $exception);
        } else {
            $accumulator->retain($context->connection(), $context->table(), $chunk);
        }
    }

    /**
     * Log a chunk insertion failure.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushContext  $context
     * @param  list<array<string, mixed>>  $chunk
     * @param  \Throwable  $exception
     * @return void
     */
    private function logChunkFailure(WritePoolFlushContext $context, array $chunk, \Throwable $exception): void
    {
        Log::warning("WritePool flush failed for table [{$context->table()}] on connection [{$context->connection()}]", [
            'connection' => $context->connection(),
            'table'      => $context->table(),
            'chunk_size' => count($chunk),
            'error'      => $exception->getMessage(),
            'exception'  => $exception,
        ]);
    }

    /**
     * Drop the targets flushed before the failed one, keep the failed target's
     * records and every later target, and return how many records the flush
     * retained.
     *
     * Connections the flush skipped stay buffered and are not counted.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushContext  $context
     * @param  list<array<string, mixed>>  $failedTargetRecords
     * @return int
     */
    private function retainBuffer(WritePoolFlushContext $context, array $failedTargetRecords): int
    {
        foreach (array_slice($context->targets(), 0, $context->targetIndex()) as [$connection, $table]) {
            $this->replace($connection, $table, []);
        }

        $this->buffer[$context->connection()][$context->table()] = $failedTargetRecords;

        $retained = 0;

        foreach (array_slice($context->targets(), $context->targetIndex()) as [$connection, $table]) {
            $retained += count($this->buffer[$connection][$table]);
        }

        return $retained;
    }
}
