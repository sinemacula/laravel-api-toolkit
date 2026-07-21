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
    /** Best-effort: log failures, continue, and drop the whole buffer. */
    case LOG = 'log';

    /** Throw on first failure; retain failed and unprocessed records. */
    case THROW = 'throw';

    /** Safe default: accumulate failures and retain records for retry. */
    case COLLECT = 'collect';
}
