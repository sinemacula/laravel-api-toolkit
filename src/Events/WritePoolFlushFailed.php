<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Events;

use SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult;

/**
 * Dispatched when a boundary write pool flush has failures.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class WritePoolFlushFailed
{
    /**
     * Create a new write pool flush failed event instance.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult  $flushResult
     * @return void
     */
    public function __construct(

        /** The flush result containing failure details */
        public WritePoolFlushResult $flushResult,
    ) {}
}
