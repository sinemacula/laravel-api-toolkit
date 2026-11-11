<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Exceptions;

use Illuminate\Support\Facades\Log;

/**
 * Reports a caught failure through the exception handler without ever throwing.
 *
 * Reporting can itself throw, so it falls back to an error log line and then to
 * nothing.
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
                // Nothing is left to report to
            }
        }
    }
}
