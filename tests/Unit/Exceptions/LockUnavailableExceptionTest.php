<?php

declare(strict_types = 1);

namespace Tests\Unit\Exceptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
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
}
