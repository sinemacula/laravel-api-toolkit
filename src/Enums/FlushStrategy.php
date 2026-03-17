<?php

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
     * Catch the exception, log at error level, continue to the next
     * chunk, and clear the entire buffer after processing.
     */
    case LOG = 'log';

    /**
     * On the first chunk failure, throw the exception and preserve
     * the failed and unprocessed records in the buffer.
     */
    case THROW = 'throw';

    /**
     * Catch all failures, accumulate them in the result, and
     * preserve the failed records in the buffer.
     */
    case COLLECT = 'collect';
}
