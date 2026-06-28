<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services;

use SineMacula\ApiToolkit\Services\Enums\ServiceStatus;

/**
 * Immutable value object representing the total outcome of a service
 * execution.
 *
 * Every execution produces exactly one result - either SUCCEEDED or
 * FAILED. A successful result carries the typed output and any
 * side-effect errors collected during afterCommit processing. A failed
 * result carries the exception that caused the failure, or null when
 * failure was signalled without throwing. Failed results never carry
 * side-effect errors.
 *
 * @template TOutput
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
     * @param  mixed  $output
     * @param  \Throwable|null  $exception
     * @param  array<int, \Throwable>  $sideEffectErrors
     */
    private function __construct(

        /** The execution outcome status */
        public ServiceStatus $status,

        /** The output produced by the service, or null */
        public mixed $output = null,

        /** Failure exception, or null when failure was signalled */
        public ?\Throwable $exception = null,

        /** Side-effect errors from afterCommit processing */
        public array $sideEffectErrors = [],
    ) {}

    /**
     * Create a successful service result.
     *
     * @param  mixed  $output
     * @param  array<int, \Throwable>  $sideEffectErrors
     * @return self<mixed>
     */
    public static function success(mixed $output = null, array $sideEffectErrors = []): self
    {
        return new self(ServiceStatus::SUCCEEDED, $output, null, $sideEffectErrors);
    }

    /**
     * Create a failed service result.
     *
     * @param  \Throwable|null  $exception
     * @param  mixed  $output
     * @return self<mixed>
     */
    public static function failure(?\Throwable $exception = null, mixed $output = null): self
    {
        return new self(ServiceStatus::FAILED, $output, $exception);
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

    /**
     * Return the output produced by the service.
     *
     * @return TOutput
     */
    public function output(): mixed
    {
        return $this->output;
    }

    /**
     * Return the output when succeeded, or the given default otherwise.
     *
     * A failed result always returns the default, even when a non-null
     * output was captured alongside the failure. This lets the caller
     * distinguish "no output" from "failed" without checking the status.
     *
     * @param  mixed  $default
     * @return mixed
     */
    public function outputOr(mixed $default): mixed
    {
        return $this->succeeded() ? $this->output : $default;
    }

    /**
     * Rethrow the captured exception when failed; otherwise return $this
     * to allow fluent chaining.
     *
     * When the result failed but no exception was captured, this method
     * returns $this without throwing.
     *
     * @return $this
     *
     * @throws \Throwable
     */
    public function throw(): static
    {
        if ($this->failed() && $this->exception !== null) {
            throw $this->exception;
        }

        return $this;
    }

    /**
     * Return the side-effect errors from afterCommit processing.
     *
     * @return array<int, \Throwable>
     */
    public function sideEffectErrors(): array
    {
        return $this->sideEffectErrors;
    }
}
