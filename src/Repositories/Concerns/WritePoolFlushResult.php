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
     * @return void
     */
    public function __construct(

        /** The number of successfully inserted chunks. */
        private readonly int $successCount,

        /** The number of failed chunks. */
        private readonly int $failureCount,

        /** Failed chunk details keyed by table name. */
        private readonly array $failures = [],

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
}
