<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Exceptions;

use Illuminate\Contracts\Debug\ExceptionHandler;

/**
 * Exception handler whose report() always fails with its own exception.
 *
 * Lets a test prove that a failure of reporting falls back to logging the
 * original exception rather than the reporting failure.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ThrowingExceptionHandler implements ExceptionHandler
{
    /** @var list<\Throwable> The exceptions passed to report(). */
    public array $reported = [];

    /**
     * Record the exception, then fail to report it.
     *
     * @param  \Throwable  $e
     * @return void
     *
     * @throws \RuntimeException
     */
    #[\Override]
    public function report(\Throwable $e): void
    {
        $this->reported[] = $e;

        throw new \RuntimeException('handler down');
    }

    /**
     * Determine if the exception should be reported.
     *
     * @param  \Throwable  $e
     * @return bool
     */
    #[\Override]
    public function shouldReport(\Throwable $e): bool
    {
        return true;
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  mixed  $request
     * @param  \Throwable  $e
     * @return never
     *
     * @throws \Throwable
     */
    #[\Override]
    public function render(mixed $request, \Throwable $e): never
    {
        throw $e;
    }

    /**
     * Render an exception to the console.
     *
     * @param  mixed  $output
     * @param  \Throwable  $e
     * @return void
     */
    #[\Override]
    public function renderForConsole(mixed $output, \Throwable $e): void {}
}
