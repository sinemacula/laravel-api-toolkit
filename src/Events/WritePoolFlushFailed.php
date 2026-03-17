<?php

namespace SineMacula\ApiToolkit\Events;

use SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult;

/**
 * Dispatched by the WritePoolFlushSubscriber when a flush operation
 * detects failures, enabling consuming applications to implement
 * custom escalation such as alerting, dead-letter queues, or
 * metrics collection.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class WritePoolFlushFailed
{
    /**
     * Create a new write pool flush failed event instance.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult  $flushResult
     * @return void
     */
    public function __construct(

        /** The flush result containing failure details. */
        public readonly WritePoolFlushResult $flushResult,

    ) {}
}
