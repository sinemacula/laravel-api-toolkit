<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Concerns;

use SineMacula\ApiToolkit\Enums\FlushStrategy;

/**
 * Mutable accumulator that tallies the outcome of a WritePool flush.
 *
 * Tracks chunk-level counts, record-level counts, failure details, and
 * the records retained for retry, then materialises an immutable
 * WritePoolFlushResult once the flush completes.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class WritePoolFlushAccumulator
{
    /** @var int The number of successfully inserted chunks. */
    private int $successCount = 0;

    /** @var int The number of failed chunks. */
    private int $failureCount = 0;

    /** @var int The number of records persisted to the database. */
    private int $flushedRecordCount = 0;

    /** @var int The number of records contained in failed chunks. */
    private int $failedRecordCount = 0;

    /** @var array<string, list<array{records: list<array<string, mixed>>, exception: string, exception_class: string}>> Failure details keyed by table name. */
    private array $failures = [];

    /** @var array<string, list<array<string, mixed>>> Records retained in the buffer for retry, keyed by table name. */
    private array $retainedRecords = [];

    /**
     * Record a successfully inserted chunk.
     *
     * @param  list<array<string, mixed>>  $chunk
     * @return void
     */
    public function recordSuccess(array $chunk): void
    {
        $this->successCount++;
        $this->flushedRecordCount += count($chunk);
    }

    /**
     * Record a failed chunk along with its failure detail.
     *
     * @param  string  $table
     * @param  list<array<string, mixed>>  $chunk
     * @param  \Throwable  $exception
     * @return void
     */
    public function recordFailure(string $table, array $chunk, \Throwable $exception): void
    {
        $this->failureCount++;
        $this->failedRecordCount += count($chunk);

        $this->failures[$table][] = [
            'records'         => $chunk,
            'exception'       => $exception->getMessage(),
            'exception_class' => $exception::class,
        ];
    }

    /**
     * Retain a failed chunk's records in the buffer for retry.
     *
     * @param  string  $table
     * @param  list<array<string, mixed>>  $chunk
     * @return void
     */
    public function retain(string $table, array $chunk): void
    {
        $this->retainedRecords[$table] = array_merge($this->retainedRecords[$table] ?? [], $chunk);
    }

    /**
     * Get the records retained in the buffer for retry.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function failedRecords(): array
    {
        return $this->retainedRecords;
    }

    /**
     * Materialise an immutable flush result for the given strategy.
     *
     * For COLLECT the retained count equals the failed record count;
     * for LOG every failed record is dropped instead of retained.
     *
     * @param  \SineMacula\ApiToolkit\Enums\FlushStrategy  $strategy
     * @param  array<int, string>  $flushedTables
     * @return \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult
     */
    public function toResult(FlushStrategy $strategy, array $flushedTables = []): WritePoolFlushResult
    {
        $retained = $strategy === FlushStrategy::LOG ? 0 : $this->failedRecordCount;
        $dropped  = $strategy === FlushStrategy::LOG ? $this->failedRecordCount : 0;

        return $this->buildResult($retained, $dropped, $flushedTables);
    }

    /**
     * Materialise an immutable flush result for the throw strategy,
     * where retained records include both failed and unprocessed rows.
     *
     * @param  int  $retainedRecordCount
     * @param  array<int, string>  $flushedTables
     * @return \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult
     */
    public function toThrowResult(int $retainedRecordCount, array $flushedTables = []): WritePoolFlushResult
    {
        return $this->buildResult($retainedRecordCount, 0, $flushedTables);
    }

    /**
     * Build the immutable flush result with explicit retain and drop
     * record counts.
     *
     * @param  int  $retainedRecordCount
     * @param  int  $droppedRecordCount
     * @param  array<int, string>  $flushedTables
     * @return \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult
     */
    private function buildResult(
        int $retainedRecordCount,
        int $droppedRecordCount,
        array $flushedTables = [],
    ): WritePoolFlushResult {
        return new WritePoolFlushResult(
            successCount: $this->successCount,
            failureCount: $this->failureCount,
            failures: $this->failures,
            flushedRecordCount: $this->flushedRecordCount,
            failedRecordCount: $this->failedRecordCount,
            retainedRecordCount: $retainedRecordCount,
            droppedRecordCount: $droppedRecordCount,
            flushedTables: $flushedTables,
        );
    }
}
