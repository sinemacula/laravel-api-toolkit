<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Concerns;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SineMacula\ApiToolkit\Enums\FlushStrategy;
use SineMacula\ApiToolkit\Exceptions\WritePoolFlushException;

/**
 * Buffers insert attribute arrays in memory grouped by table and flushes them
 * as chunked bulk INSERT statements.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class WritePool
{
    /** @var array<string, list<array<string, mixed>>> Records buffered by table name. */
    private array $buffer = [];

    /** @var \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult|null The retained outcome of the most recent auto-flush; persists until the next auto-flush overwrites it. */
    private ?WritePoolFlushResult $lastAutoFlushResult = null;

    /**
     * Create a new write pool instance.
     *
     * @param  int  $chunkSize
     * @param  int  $poolLimit
     * @param  \SineMacula\ApiToolkit\Enums\FlushStrategy  $strategy
     * @param  bool  $transactional
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
    ) {}

    /**
     * Add an attribute array to the buffer for the given table.
     *
     * When the total buffered record count reaches or exceeds the configured
     * pool limit, an automatic flush is triggered using the instance strategy.
     * Under the throw strategy this auto-flush may raise out of add().
     *
     * @param  string  $table
     * @param  array<string, mixed>  $attributes
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\WritePoolFlushException
     */
    public function add(string $table, array $attributes): void
    {
        $this->buffer[$table][] = $attributes;

        if ($this->count() < $this->poolLimit) {
            return;
        }

        // Retain the outcome so lastAutoFlushResult() can surface a
        // non-throwing collect/log auto-flush failure.
        $this->lastAutoFlushResult = $this->flush();
    }

    /**
     * Flush all buffered records as chunked bulk INSERT statements.
     *
     * Records are grouped by table and split into chunks of at most the
     * configured chunk size. The active strategy determines how chunk failures
     * are handled and whether the buffer is retained.
     *
     * @param  \SineMacula\ApiToolkit\Enums\FlushStrategy|null  $strategy
     * @return \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\WritePoolFlushException
     */
    public function flush(?FlushStrategy $strategy = null): WritePoolFlushResult
    {
        if ($this->isEmpty()) {
            return new WritePoolFlushResult(successCount: 0, failureCount: 0);
        }

        try {
            $result = $this->executeFlush($strategy ?? $this->strategy);
        } catch (WritePoolFlushException $exception) {
            $this->invalidateFlushedTables($exception->flushResult());

            throw $exception;
        }

        $this->invalidateFlushedTables($result);

        return $result;
    }

    /**
     * Return the outcome of the most recent auto-flush triggered by add(), or
     * null if no auto-flush has occurred.
     *
     * This is a supported observability hook. Under the non-throwing collect
     * and log strategies an auto-flush that fails inside add() returns without
     * raising, so this accessor is the only in-process signal that an
     * auto-flush failed. Because the pool is a scoped singleton it is reachable
     * after a deferred write via app(WritePool::class)->lastAutoFlushResult(),
     * which resolves the same instance the Deferrable trait and the boundary
     * flush subscriber use.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult|null
     */
    public function lastAutoFlushResult(): ?WritePoolFlushResult
    {
        return $this->lastAutoFlushResult;
    }

    /**
     * Get the total number of buffered records across all tables.
     *
     * @return int
     */
    public function count(): int
    {
        $count = 0;

        foreach ($this->buffer as $records) {
            $count += count($records);
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
     * Invalidate the per-query cache for the tables a flush persisted.
     *
     * Gated by the invalidate_query_cache config flag, so every flush - the
     * auto-flush triggered when the pool limit is reached as well as the
     * boundary flush - keeps a Cacheable repository's cached collections in
     * step with the rows it just committed.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult  $result
     * @return void
     */
    private function invalidateFlushedTables(WritePoolFlushResult $result): void
    {
        if (!Config::get('api-toolkit.deferred_writes.invalidate_query_cache', true)) {
            return;
        }

        (new DeferredWriteCacheInvalidator)->invalidate($result->flushedTables());
    }

    /**
     * Execute the flush operation with the given strategy.
     *
     * @param  \SineMacula\ApiToolkit\Enums\FlushStrategy  $strategy
     * @return \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\WritePoolFlushException
     */
    private function executeFlush(FlushStrategy $strategy): WritePoolFlushResult
    {
        $accumulator = new WritePoolFlushAccumulator;

        $tables = array_keys($this->buffer);

        foreach ($tables as $tableIndex => $table) {

            $chunks  = array_chunk($this->buffer[$table], $this->chunkSize);
            $context = new WritePoolFlushContext($strategy, $table, $chunks, $tables, $tableIndex);

            $this->flushTable($context, $accumulator);
        }

        $this->buffer = $strategy === FlushStrategy::COLLECT
            ? $accumulator->failedRecords()
            : [];

        return $accumulator->toResult($strategy, $tables);
    }

    /**
     * Flush a single table's chunks, optionally inside a transaction.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushContext  $context
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushAccumulator  $accumulator
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\WritePoolFlushException
     */
    private function flushTable(WritePoolFlushContext $context, WritePoolFlushAccumulator $accumulator): void
    {
        if (!$this->transactional) {
            $this->flushChunks($context, $accumulator);

            return;
        }

        $this->flushTableTransactionally($context, $accumulator);
    }

    /**
     * Flush a table's chunks inside a database transaction so the table's
     * inserts are applied all-or-nothing.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushContext  $context
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushAccumulator  $accumulator
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\WritePoolFlushException
     */
    private function flushTableTransactionally(WritePoolFlushContext $context, WritePoolFlushAccumulator $accumulator): void
    {
        try {
            DB::transaction(function () use ($context): void {

                foreach ($context->chunks() as $chunk) {
                    DB::table($context->table())->insert($chunk);
                }
            });

            $this->recordTableSuccess($context, $accumulator);
        } catch (\Throwable $exception) {
            $this->handleTableRollback($context, $accumulator, $exception);
        }
    }

    /**
     * Account for a table whose transaction committed, counting every chunk and
     * record as flushed.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushContext  $context
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushAccumulator  $accumulator
     * @return void
     */
    private function recordTableSuccess(WritePoolFlushContext $context, WritePoolFlushAccumulator $accumulator): void
    {
        foreach ($context->chunks() as $chunk) {
            $accumulator->recordSuccess($chunk);
        }
    }

    /**
     * Account for a table whose transaction rolled back, treating the whole
     * table's records as a single failure for the strategy.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushContext  $context
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushAccumulator  $accumulator
     * @param  \Throwable  $exception
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\WritePoolFlushException
     */
    private function handleTableRollback(WritePoolFlushContext $context, WritePoolFlushAccumulator $accumulator, \Throwable $exception): void
    {

        $records = array_merge(...$context->chunks());

        $accumulator->recordFailure($context->table(), $records, $exception);

        if ($context->strategy() === FlushStrategy::THROW) {
            // Whole-table retain: the failed table keeps its merged records.
            $this->retainBuffer($context, $records);

            throw new WritePoolFlushException($accumulator->toThrowResult($this->count(), $context->tables()), $exception);
        }

        if ($context->strategy() === FlushStrategy::LOG) {
            $this->logChunkFailure($context->table(), $records, $exception);
        } else {
            $accumulator->retain($context->table(), $records);
        }
    }

    /**
     * Insert each chunk of a table, accumulating the outcome.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushContext  $context
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushAccumulator  $accumulator
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\WritePoolFlushException
     */
    private function flushChunks(WritePoolFlushContext $context, WritePoolFlushAccumulator $accumulator): void
    {
        foreach ($context->chunks() as $chunkIndex => $chunk) {

            try {
                DB::table($context->table())->insert($chunk);
                $accumulator->recordSuccess($chunk);
            } catch (\Throwable $exception) {
                $this->handleChunkFailure($context->withChunkIndex($chunkIndex), $accumulator, $chunk, $exception);
            }
        }
    }

    /**
     * Handle a single chunk insertion failure for the active strategy.
     *
     * The context must carry the chunk index (via withChunkIndex) so that
     * retainUnprocessedRecords can compute the remaining chunks correctly.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushContext  $context
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushAccumulator  $accumulator
     * @param  list<array<string, mixed>>  $chunk
     * @param  \Throwable  $exception
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\WritePoolFlushException
     */
    private function handleChunkFailure(WritePoolFlushContext $context, WritePoolFlushAccumulator $accumulator, array $chunk, \Throwable $exception): void
    {

        $accumulator->recordFailure($context->table(), $chunk, $exception);

        if ($context->strategy() === FlushStrategy::THROW) {
            // Per-chunk retain: failed chunk + remaining chunks + remaining
            // tables. Mirror of whole-table retain in handleTableRollback -
            // keep in sync if either path changes.
            $this->retainUnprocessedRecords($context, $chunk);

            throw new WritePoolFlushException($accumulator->toThrowResult($this->count(), $context->tables()), $exception);
        }

        if ($context->strategy() === FlushStrategy::LOG) {
            $this->logChunkFailure($context->table(), $chunk, $exception);
        } else {
            $accumulator->retain($context->table(), $chunk);
        }
    }

    /**
     * Log a chunk insertion failure.
     *
     * @param  string  $table
     * @param  list<array<string, mixed>>  $chunk
     * @param  \Throwable  $exception
     * @return void
     */
    private function logChunkFailure(string $table, array $chunk, \Throwable $exception): void
    {
        Log::error("WritePool flush failed for table [{$table}]", [
            'table'      => $table,
            'chunk_size' => count($chunk),
            'error'      => $exception->getMessage(),
            'exception'  => $exception,
        ]);
    }

    /**
     * Retain the failed chunk and all unprocessed records in the buffer.
     *
     * The context must carry the chunk index (set via withChunkIndex) so that
     * the remaining chunks for this table can be computed correctly.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushContext  $context
     * @param  list<array<string, mixed>>  $chunk
     * @return void
     */
    private function retainUnprocessedRecords(WritePoolFlushContext $context, array $chunk): void
    {
        $chunkIndex      = (int) $context->chunkIndex();
        $remainingChunks = array_slice($context->chunks(), $chunkIndex + 1);

        $this->retainBuffer($context, array_merge($chunk, ...$remainingChunks));
    }

    /**
     * Rebuild the buffer to retain the failed table's records plus every table
     * not yet reached, discarding the tables already flushed.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushContext  $context
     * @param  list<array<string, mixed>>  $failedTableRecords
     * @return void
     */
    private function retainBuffer(WritePoolFlushContext $context, array $failedTableRecords): void
    {
        $retainedBuffer = [$context->table() => $failedTableRecords];

        foreach (array_slice($context->tables(), $context->tableIndex() + 1) as $remainingTable) {
            $retainedBuffer[$remainingTable] = $this->buffer[$remainingTable];
        }

        $this->buffer = $retainedBuffer;
    }
}
