<?php

namespace SineMacula\ApiToolkit\Repositories\Concerns;

/**
 * Immutable value object encapsulating the outcome of a WritePool
 * flush operation.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class WritePoolFlushResult
{
    /**
     * Create a new flush result instance.
     *
     * @param  int  $successCount
     * @param  int  $failureCount
     * @param  array<string, list<array{records: list<array<string, mixed>>, exception: string}>>  $failures
     * @param  int  $flushedRecordCount
     * @param  int  $failedRecordCount
     * @param  int  $retainedRecordCount
     * @param  int  $droppedRecordCount
     * @return void
     */
    public function __construct(
        private readonly int $successCount,
        private readonly int $failureCount,
        private readonly array $failures = [],
        private readonly int $flushedRecordCount = 0,
        private readonly int $failedRecordCount = 0,
        private readonly int $retainedRecordCount = 0,
        private readonly int $droppedRecordCount = 0,
    ) {}

    /**
     * Determine whether the flush completed without any failures.
     *
     * @return bool
     */
    public function isSuccessful(): bool
    {
        return $this->failureCount === 0;
    }

    /**
     * Get the number of successfully inserted chunks.
     *
     * @return int
     */
    public function successCount(): int
    {
        return $this->successCount;
    }

    /**
     * Get the number of failed chunks.
     *
     * @return int
     */
    public function failureCount(): int
    {
        return $this->failureCount;
    }

    /**
     * Get the total number of chunks processed.
     *
     * @return int
     */
    public function totalCount(): int
    {
        return $this->successCount + $this->failureCount;
    }

    /**
     * Get the failure details keyed by table name.
     *
     * @return array<string, list<array{records: list<array<string, mixed>>, exception: string}>>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * Get the number of records persisted to the database.
     *
     * @return int
     */
    public function flushedRecordCount(): int
    {
        return $this->flushedRecordCount;
    }

    /**
     * Get the number of records contained in failed chunks.
     *
     * @return int
     */
    public function failedRecordCount(): int
    {
        return $this->failedRecordCount;
    }

    /**
     * Get the number of records retained in the buffer for retry.
     *
     * @return int
     */
    public function retainedRecordCount(): int
    {
        return $this->retainedRecordCount;
    }

    /**
     * Get the number of records discarded without retry.
     *
     * @return int
     */
    public function droppedRecordCount(): int
    {
        return $this->droppedRecordCount;
    }
}
