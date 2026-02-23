<?php

namespace Tests\Unit\Exceptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SineMacula\ApiToolkit\Exceptions\ServiceLockException;

/**
 * Tests for the ServiceLockException.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ServiceLockException::class)]
class ServiceLockExceptionTest extends TestCase
{
    /**
     * Test that the exception extends RuntimeException.
     *
     * @return void
     */
    public function testExtendsRuntimeException(): void
    {
        $exception = new ServiceLockException;

        static::assertInstanceOf(\RuntimeException::class, $exception);
    }

    /**
     * Test that the exception can be instantiated with a message.
     *
     * @return void
     */
    public function testCanBeInstantiatedWithMessage(): void
    {
        $message   = 'Could not acquire service lock';
        $exception = new ServiceLockException($message);

        static::assertSame($message, $exception->getMessage());
    }

    /**
     * Test that the exception can be instantiated with a code.
     *
     * @return void
     */
    public function testCanBeInstantiatedWithCode(): void
    {
        $exception = new ServiceLockException(\Error::class, 99);

        static::assertSame(99, $exception->getCode());
    }

    /**
     * Test that the exception can be instantiated with a previous exception.
     *
     * @return void
     */
    public function testCanBeInstantiatedWithPreviousException(): void
    {
        $previous  = new \RuntimeException('Previous error');
        $exception = new ServiceLockException(\Error::class, 0, $previous);

        static::assertSame($previous, $exception->getPrevious());
    }

    /**
     * Test that the exception has default empty message.
     *
     * @return void
     */
    public function testDefaultMessageIsEmpty(): void
    {
        $exception = new ServiceLockException;

        static::assertSame('', $exception->getMessage());
    }

    /**
     * Test that the exception has default zero code.
     *
     * @return void
     */
    public function testDefaultCodeIsZero(): void
    {
        $exception = new ServiceLockException;

        static::assertSame(0, $exception->getCode());
    }
}
