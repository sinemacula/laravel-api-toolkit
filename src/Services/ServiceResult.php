<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services;

use SineMacula\ApiToolkit\Exceptions\ServiceFailedException;
use SineMacula\ApiToolkit\Services\Enums\ServiceStatus;

/**
 * Immutable outcome of a service execution.
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
     * Attached output is reachable only through output(); outputOr() still
     * returns its default.
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
     * @param  mixed  $default
     * @return mixed
     */
    public function outputOr(mixed $default): mixed
    {
        return $this->succeeded() ? $this->output : $default;
    }

    /**
     * Throw when failed; otherwise return $this.
     *
     * Throws the captured exception, or a ServiceFailedException when there is
     * none.
     *
     * @return $this
     *
     * @throws \Throwable
     */
    public function throw(): self
    {
        if ($this->failed()) {
            throw $this->exception ?? new ServiceFailedException($this);
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
