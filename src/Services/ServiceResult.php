<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services;

use SineMacula\ApiToolkit\Services\Enums\ServiceStatus;

/**
 * Immutable value object representing the outcome of a service execution.
 *
 * Carries the service status, optional result data, and optional exception
 * context in a single self-describing return value. The exception is null
 * when the service succeeded, or when failure was signalled by the handler
 * returning false rather than throwing.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ServiceResult
{
    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\Services\Enums\ServiceStatus  $status
     * @param  mixed  $data
     * @param  \Throwable|null  $exception
     */
    private function __construct(

        /** The execution outcome status */
        public ServiceStatus $status,

        /** Optional result data produced by the service */
        public mixed $data = null,

        /** Optional exception captured on failure */
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
        return new self(ServiceStatus::SUCCEEDED, $data);
    }

    /**
     * Create a failed service result.
     *
     * @param  \Throwable|null  $exception
     * @param  mixed  $data
     * @return self
     */
    public static function failure(?\Throwable $exception = null, mixed $data = null): self
    {
        return new self(ServiceStatus::FAILED, $data, $exception);
    }

    /**
     * Determine whether the service execution succeeded.
     *
     * @return bool
     */
    public function succeeded(): bool
    {
        return $this->status === ServiceStatus::SUCCEEDED;
    }

    /**
     * Determine whether the service execution failed.
     *
     * @return bool
     */
    public function failed(): bool
    {
        return $this->status === ServiceStatus::FAILED;
    }
}
