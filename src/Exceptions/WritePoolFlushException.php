<?php

namespace SineMacula\ApiToolkit\Exceptions;

use SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult;

/**
 * Thrown by the throw flush strategy when a chunk insert fails during
 * a WritePool flush operation.
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
     * @param  \Throwable  $previous
     * @return void
     */
    public function __construct(

        /** The partial flush result for inspection. */
        private readonly WritePoolFlushResult $flushResult,

        // The root cause exception
        \Throwable $previous,

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
}
