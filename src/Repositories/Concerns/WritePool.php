<?php

namespace SineMacula\ApiToolkit\Repositories\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SineMacula\ApiToolkit\Enums\FlushStrategy;
use SineMacula\ApiToolkit\Exceptions\WritePoolFlushException;

/**
 * Buffers insert attribute arrays in memory grouped by table
 * and flushes them as chunked bulk INSERT statements.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class WritePool
{
    /** @var array<string, list<array<string, mixed>>> Records buffered by table name. */
    private array $buffer = [];

    /** @var \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult|null The result from the most recent auto-flush. */
    private ?WritePoolFlushResult $lastAutoFlushResult = null;

    /**
     * Create a new write pool instance.
     *
     * @param  int  $chunkSize
     * @param  int  $poolLimit
     * @param  \SineMacula\ApiToolkit\Enums\FlushStrategy  $strategy
     * @return void
     */
    public function __construct(

        /** The maximum number of records per bulk insert chunk. */
        private readonly int $chunkSize,

        /** The record count threshold that triggers an automatic flush. */
        private readonly int $poolLimit,

        /** The failure handling strategy for flush operations. */
        private readonly FlushStrategy $strategy = FlushStrategy::LOG,

    ) {}

    /**
     * Add an attribute array to the buffer for the given table.
     *
     * When the total buffered record count reaches or exceeds the
     * configured pool limit, an automatic flush is triggered.
     *
     * @param  string  $table
     * @param  array<string, mixed>  $attributes
     * @return void
     */
    public function add(string $table, array $attributes): void
    {
        $this->buffer[$table][] = $attributes;

        if ($this->count() >= $this->poolLimit) {

            $autoStrategy = $this->strategy === FlushStrategy::LOG
                ? FlushStrategy::LOG
                : FlushStrategy::COLLECT;

            $this->lastAutoFlushResult = $this->flush($autoStrategy);
        }
    }

    /**
     * Flush all buffered records as chunked bulk INSERT statements.
     *
     * Records are grouped by table and split into chunks of at most
     * the configured chunk size. The active strategy determines how
     * chunk failures are handled and whether the buffer is retained.
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

        return $this->executeFlush($strategy ?? $this->strategy);
    }

    /**
     * Return the result from the most recent auto-flush triggered
     * by add(), or null if no auto-flush has occurred.
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
     * Execute the flush operation with the given strategy.
     *
     * @param  \SineMacula\ApiToolkit\Enums\FlushStrategy  $strategy
     * @return \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\WritePoolFlushException
     */
    private function executeFlush(FlushStrategy $strategy): WritePoolFlushResult
    {
        $successCount  = 0;
        $failureCount  = 0;
        $failures      = [];
        $failedRecords = [];

        $tables = array_keys($this->buffer);

        foreach ($tables as $tableIndex => $table) {

            $chunks = array_chunk($this->buffer[$table], $this->chunkSize);

            foreach ($chunks as $chunkIndex => $chunk) {

                try {
                    DB::table($table)->insert($chunk);
                    $successCount++;
                } catch (\Throwable $e) {

                    $failureCount++;
                    $failures[$table][] = ['records' => $chunk, 'exception' => $e->getMessage()];

                    if ($strategy === FlushStrategy::THROW) {
                        $this->retainUnprocessedRecords($table, $chunk, $chunks, $chunkIndex, $tables, $tableIndex);

                        throw new WritePoolFlushException(new WritePoolFlushResult($successCount, $failureCount, $failures), $e);
                    }

                    $this->handleNonThrowFailure($strategy, $table, $chunk, $e, $failedRecords);
                }
            }
        }

        $this->buffer = $strategy === FlushStrategy::COLLECT
            ? $failedRecords
            : [];

        return new WritePoolFlushResult($successCount, $failureCount, $failures);
    }

    /**
     * Handle a non-throw chunk failure by logging or collecting.
     *
     * @param  \SineMacula\ApiToolkit\Enums\FlushStrategy  $strategy
     * @param  string  $table
     * @param  list<array<string, mixed>>  $chunk
     * @param  \Throwable  $exception
     * @param  array<string, list<array<string, mixed>>>  &$failedRecords
     * @return void
     */
    private function handleNonThrowFailure(
        FlushStrategy $strategy,
        string $table,
        array $chunk,
        \Throwable $exception,
        array &$failedRecords,
    ): void {

        if ($strategy === FlushStrategy::LOG) {
            $this->logChunkFailure($table, $chunk, $exception);
        } else {
            $this->collectFailedChunk($table, $chunk, $failedRecords);
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
        ]);
    }

    /**
     * Collect the failed chunk records for later retry.
     *
     * @param  string  $table
     * @param  list<array<string, mixed>>  $chunk
     * @param  array<string, list<array<string, mixed>>>  &$failedRecords
     * @return void
     */
    private function collectFailedChunk(string $table, array $chunk, array &$failedRecords): void
    {
        $failedRecords[$table] = array_merge($failedRecords[$table] ?? [], $chunk);
    }

    /**
     * Retain the failed chunk and all unprocessed records in the buffer.
     *
     * @param  string  $table
     * @param  list<array<string, mixed>>  $chunk
     * @param  list<list<array<string, mixed>>>  $chunks
     * @param  int  $chunkIndex
     * @param  list<string>  $tables
     * @param  int  $tableIndex
     * @return void
     */
    private function retainUnprocessedRecords(
        string $table,
        array $chunk,
        array $chunks,
        int $chunkIndex,
        array $tables,
        int $tableIndex,
    ): void {

        $remainingChunks = array_slice($chunks, $chunkIndex + 1);
        $retainedBuffer  = [];

        $retainedBuffer[$table] = array_merge($chunk, ...$remainingChunks);

        foreach (array_slice($tables, $tableIndex + 1) as $remainingTable) {
            $retainedBuffer[$remainingTable] = $this->buffer[$remainingTable];
        }

        $this->buffer = $retainedBuffer;
    }
}
