<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Exceptions;

use Illuminate\Support\Facades\Log;

/**
 * Reports a caught failure through the exception handler without ever throwing.
 *
 * Used where a failure is caught at a boundary that must not crash, or after an
 * outcome has already been committed. Reporting itself can throw (the logger
 * cannot be built, a reportable callback fails), so a reporting failure falls
 * back to an error log line carrying the original exception, and a failure of
 * that log write is swallowed.
 *
 * @internal
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class FailureReporter
{
    /**
     * Report the given exception, falling back to an error log line when
     * reporting fails.
     *
     * @param  \Throwable  $exception
     * @param  string  $fallbackMessage
     * @return void
     */
    public static function report(\Throwable $exception, string $fallbackMessage): void
    {
        try {
            report($exception);
        } catch (\Throwable) { // @phpstan-ignore catch.neverThrown
            try {
                Log::error($fallbackMessage, ['exception' => $exception]);
            } catch (\Throwable) {
                // Nothing is left to report to.
            }
        }
    }
}
