<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Concerns;

/**
 * Immutable value object encapsulating the outcome of a WritePool flush
 * operation.
 *
 * @phpstan-type SerializedFlushResult array{
 *     successCount: int,
 *     failureCount: int,
 *     failures: array<string, list<array{
 *         records: list<array<string, mixed>>,
 *         exception: string,
 *         exception_class: string,
 *     }>>,
 *     flushedRecordCount: int,
 *     failedRecordCount: int,
 *     retainedRecordCount: int,
 *     droppedRecordCount: int,
 *     flushedTables: array<int, string>,
 * }
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class WritePoolFlushResult
{
    /**
     * Create a new flush result instance.
     *
     * @param  int  $successCount
     * @param  int  $failureCount
     * @param  array<string, list<array{records: list<array<string, mixed>>, exception: string, exception_class: string}>>  $failures
     * @param  int  $flushedRecordCount
     * @param  int  $failedRecordCount
     * @param  int  $retainedRecordCount
     * @param  int  $droppedRecordCount
     * @param  array<int, string>  $flushedTables
     * @param  \Throwable|null  $firstException
     * @return void
     */
    public function __construct(

        /** The number of successfully inserted chunks. */
        private int $successCount,

        /** The number of failed chunks. */
        private int $failureCount,

        /** The failure details keyed by table name. */
        private array $failures = [],

        /** The number of records persisted to the database. */
        private int $flushedRecordCount = 0,

        /** The number of records contained in failed chunks. */
        private int $failedRecordCount = 0,

        /** The number of records retained in the buffer for retry. */
        private int $retainedRecordCount = 0,

        /** The number of records discarded without retry. */
        private int $droppedRecordCount = 0,

        /** The distinct tables this flush attempted to persist. */
        private array $flushedTables = [],

        /** The first underlying failure, if any chunk failed. */
        private ?\Throwable $firstException = null,
    ) {}

    /**
     * Serialize the result without the underlying exception, whose trace may
     * hold values that cannot be serialized.
     *
     * @return SerializedFlushResult
     *
     * @SuppressWarnings("php:S100")
     */
    public function __serialize(): array
    {
        return [
            'successCount'        => $this->successCount,
            'failureCount'        => $this->failureCount,
            'failures'            => $this->failures,
            'flushedRecordCount'  => $this->flushedRecordCount,
            'failedRecordCount'   => $this->failedRecordCount,
            'retainedRecordCount' => $this->retainedRecordCount,
            'droppedRecordCount'  => $this->droppedRecordCount,
            'flushedTables'       => $this->flushedTables,
        ];
    }

    /**
     * Restore a serialized result; the underlying exception is not restored.
     *
     * @param  SerializedFlushResult  $data
     * @return void
     *
     * @SuppressWarnings("php:S100")
     */
    public function __unserialize(array $data): void
    {
        $this->successCount        = $data['successCount'];
        $this->failureCount        = $data['failureCount'];
        $this->failures            = $data['failures'];
        $this->flushedRecordCount  = $data['flushedRecordCount'];
        $this->failedRecordCount   = $data['failedRecordCount'];
        $this->retainedRecordCount = $data['retainedRecordCount'];
        $this->droppedRecordCount  = $data['droppedRecordCount'];
        $this->flushedTables       = $data['flushedTables'];
        $this->firstException      = null;
    }

    /**
     * Get the distinct tables whose buffered records this flush attempted to
     * persist. Used to invalidate each table's downstream per-query cache.
     *
     * @return array<int, string>
     */
    public function flushedTables(): array
    {
        return $this->flushedTables;
    }

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
     * @return array<string, list<array{records: list<array<string, mixed>>, exception: string, exception_class: string}>>
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

    /**
     * Get the first underlying failure raised during the flush, if any.
     *
     * Not retained when the result is serialized.
     *
     * @return \Throwable|null
     */
    public function firstException(): ?\Throwable
    {
        return $this->firstException;
    }
}
