<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Events;

use SineMacula\ApiToolkit\Services\Contracts\Actor;
use SineMacula\ApiToolkit\Services\ServiceResult;

/**
 * Event payload emitted when a service execution fails.
 *
 * Dispatched by the service runner immediately after a failed
 * execution. The carried result's exception property holds the
 * failure cause. Consumers may subscribe for audit logging,
 * telemetry, or other observability concerns.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ServiceFailed
{
    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\Services\Contracts\Actor  $actor
     * @param  class-string<\SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Contracts\ServiceInput, mixed>>  $service
     * @param  \SineMacula\ApiToolkit\Services\ServiceResult<mixed>  $result
     * @param  float  $duration
     * @param  string  $correlationId
     * @param  array<string, mixed>  $inputSummary
     */
    public function __construct(

        /** The actor that initiated the service execution */
        public Actor $actor,

        /** The fully-qualified class name of the service that was executed */
        public string $service,

        /** The failed result carrying the failure exception */
        public ServiceResult $result,

        /** Execution duration in seconds */
        public float $duration,

        /** Correlation identifier for request tracing */
        public string $correlationId,

        /** Snapshot of the input fields passed to the service */
        public array $inputSummary = [],
    ) {}
}
