<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Exceptions;

use SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult;

/**
 * Raised when a WritePool flush fails to persist writes that were not marked
 * disposable.
 *
 * Thrown by the throw flush strategy when a chunk insert fails, and built by
 * the boundary flush subscriber from a collected result with failures so the
 * failure can be reported. The report context carries counts and table names
 * only, never the buffered records.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class WritePoolFlushException extends \RuntimeException
{
    /**
     * Create a new write pool flush exception instance.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult  $flushResult
     * @param  \Throwable|null  $previous
     * @return void
     */
    public function __construct(

        /** The partial flush result for inspection. */
        private readonly WritePoolFlushResult $flushResult,

        // The root cause exception
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'WritePool flush failed: %d chunk(s) failed out of %d total.',
                $flushResult->failureCount(),
                $flushResult->totalCount(),
            ),
            0,
            $previous,
        );
    }

    /**
     * Return the partial flush result for inspection.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult
     */
    public function flushResult(): WritePoolFlushResult
    {
        return $this->flushResult;
    }

    /**
     * Get the exception's context information for reporting.
     *
     * @return array{failure_count: int, total_count: int, failed_record_count: int, retained_record_count: int, dropped_record_count: int, tables: list<string>}
     */
    public function context(): array
    {
        return [
            'failure_count'         => $this->flushResult->failureCount(),
            'total_count'           => $this->flushResult->totalCount(),
            'failed_record_count'   => $this->flushResult->failedRecordCount(),
            'retained_record_count' => $this->flushResult->retainedRecordCount(),
            'dropped_record_count'  => $this->flushResult->droppedRecordCount(),
            'tables'                => array_keys($this->flushResult->failures()),
        ];
    }
}
