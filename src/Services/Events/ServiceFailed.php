<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Events;

/**
 * Event payload emitted when a service execution fails.
 *
 * Dispatched by the service runner immediately after a failed execution. The
 * carried result's exception property holds the failure cause. Consumers may
 * subscribe for audit logging, telemetry, or other observability concerns.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ServiceFailed extends ServiceEvent {}
