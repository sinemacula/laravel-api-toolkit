<?php

namespace SineMacula\ApiToolkit\Services;

use SineMacula\ApiToolkit\Services\Enums\ServiceStatus;

/**
 * Immutable value object representing the outcome of a service execution.
 *
 * Carries the service status, optional result data, and optional exception
 * context in a single self-describing return value.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ServiceResult
{
    /**
     * Create a new service result instance.
     *
     * @param  \SineMacula\ApiToolkit\Services\Enums\ServiceStatus  $status
     * @param  mixed  $data
     * @param  \Throwable|null  $exception
     */
    public function __construct(
        public ServiceStatus $status,
        public mixed $data = null,
        public ?\Throwable $exception = null,
    ) {}

    /**
     * Create a successful service result.
     *
     * @param  mixed  $data
     * @return self
     */
    public static function success(mixed $data = null): self
    {
        return new self(ServiceStatus::Succeeded, $data);
    }

    /**
     * Create a failed service result.
     *
     * @param  \Throwable  $exception
     * @param  mixed  $data
     * @return self
     */
    public static function failure(\Throwable $exception, mixed $data = null): self
    {
        return new self(ServiceStatus::Failed, $data, $exception);
    }

    /**
     * Determine whether the service execution succeeded.
     *
     * @return bool
     */
    public function succeeded(): bool
    {
        return $this->status === ServiceStatus::Succeeded;
    }

    /**
     * Determine whether the service execution failed.
     *
     * @return bool
     */
    public function failed(): bool
    {
        return $this->status === ServiceStatus::Failed;
    }
}
