<?php

declare(strict_types = 1);

namespace Tests\Unit\Exceptions;

use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Contracts\Debug\ShouldntReport;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\LockUnavailableException;

/**
 * Tests for the LockUnavailableException.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(LockUnavailableException::class)]
final class LockUnavailableExceptionTest extends TestCase
{
    /**
     * Test that the exception is a RuntimeException carrying the given message.
     *
     * @return void
     */
    public function testIsRuntimeException(): void
    {
        $message   = 'Unable to acquire the cache lock; the resource is currently locked.';
        $exception = new LockUnavailableException($message);

        self::assertInstanceOf(\RuntimeException::class, $exception);
        self::assertSame($message, $exception->getMessage());
    }

    /**
     * Test that lock contention is never reported, even when the toolkit
     * exception handler has not been registered.
     *
     * @return void
     */
    public function testIsNotReportedWithoutTheToolkitHandler(): void
    {
        assert($this->app !== null);

        $handler = $this->app->make(ExceptionHandlerContract::class);

        self::assertInstanceOf(ShouldntReport::class, new LockUnavailableException);
        self::assertFalse($handler->shouldReport(new LockUnavailableException));
    }
}
