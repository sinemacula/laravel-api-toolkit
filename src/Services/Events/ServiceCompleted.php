<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Events;

/**
 * Event payload emitted when a service execution succeeds.
 *
 * Dispatched by the service runner immediately after a successful execution
 * completes. Consumers may subscribe for audit logging, telemetry, or other
 * observability concerns.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ServiceCompleted extends ServiceEvent {}
