<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Enums;

/**
 * Defines the strategies for handling failures during a WritePool flush.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum FlushStrategy: string
{
    /**
     * Opt-in best-effort: catch the exception, log at error level, continue to
     * the next chunk, and clear the entire buffer after processing. Failed
     * records are dropped, so this strategy is only appropriate for genuinely
     * disposable writes such as audit, analytics, or telemetry.
     */
    case LOG = 'log';

    /**
     * Safe explicit: on the first chunk failure, throw the exception carrying
     * the partial result and preserve the failed and unprocessed records in the
     * buffer. No record is dropped. Intended for callers that own an explicit
     * flush site and want to be told loudly when a write fails.
     */
    case THROW = 'throw';

    /**
     * Safe default: catch all failures, accumulate them in the result, and
     * retain the failed records in the buffer for the next flush attempt. No
     * record is dropped and no exception escapes, so a boundary flush surfaces
     * failures loudly without disrupting the lifecycle.
     */
    case COLLECT = 'collect';
}
