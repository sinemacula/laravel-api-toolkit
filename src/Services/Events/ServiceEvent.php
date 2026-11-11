<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Events;

use SineMacula\ApiToolkit\Services\Contracts\Actor;
use SineMacula\ApiToolkit\Services\ServiceResult;

/**
 * Shared payload for service lifecycle events.
 *
 * The inputSummary is the input's toArray() and is not redacted here.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract readonly class ServiceEvent
{
    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\Services\Contracts\Actor  $actor
     * @param  class-string<\SineMacula\ApiToolkit\Services\Service>  $service
     * @param  \SineMacula\ApiToolkit\Services\ServiceResult<mixed>  $result
     * @param  float  $duration
     * @param  string  $correlationId
     * @param  array<string, mixed>  $inputSummary
     *
     * @phpstan-ignore missingType.generics
     */
    public function __construct(

        /** The actor that initiated the service execution */
        public Actor $actor,

        /** The fully-qualified class name of the service that was executed */
        public string $service,

        /** The result produced by the service */
        public ServiceResult $result,

        /** Execution duration in seconds */
        public float $duration,

        /** Correlation identifier for request tracing */
        public string $correlationId,

        /** Input snapshot, as produced by the input's toArray() */
        public array $inputSummary = [],
    ) {}
}
