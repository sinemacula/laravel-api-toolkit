<?php

declare(strict_types = 1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\Exceptions;
use Tests\Fixtures\Exceptions\ThrowingExceptionHandler;

/**
 * Replaces the application's exception handler with one whose report() fails.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait SwapsExceptionHandler
{
    /**
     * Bind an exception handler whose report() always throws.
     *
     * @return \Tests\Fixtures\Exceptions\ThrowingExceptionHandler
     */
    protected function useThrowingExceptionHandler(): ThrowingExceptionHandler
    {
        $handler = new ThrowingExceptionHandler;

        Exceptions::swap($handler);

        return $handler;
    }
}
