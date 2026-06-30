<?php

declare(strict_types = 1);

namespace Tests\Unit\Exceptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Exceptions\LockOperationException;

/**
 * Tests for the LockOperationException.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(LockOperationException::class)]
final class LockOperationExceptionTest extends TestCase
{
    /**
     * Test that the exception extends RuntimeException.
     *
     * @return void
     */
    public function testExtendsRuntimeException(): void
    {
        $exception = new LockOperationException;

        self::assertInstanceOf(\RuntimeException::class, $exception);
    }

    /**
     * Test that the exception can be instantiated with a message.
     *
     * @return void
     */
    public function testCanBeInstantiatedWithMessage(): void
    {
        $message   = 'The lock key must be provided';
        $exception = new LockOperationException($message);

        self::assertSame($message, $exception->getMessage());
    }

    /**
     * Test that the exception can be instantiated with a code.
     *
     * @return void
     */
    public function testCanBeInstantiatedWithCode(): void
    {
        $exception = new LockOperationException(\Error::class, 42);

        self::assertSame(42, $exception->getCode());
    }

    /**
     * Test that the exception can be instantiated with a previous exception.
     *
     * @return void
     */
    public function testCanBeInstantiatedWithPreviousException(): void
    {
        $previous  = new \RuntimeException('Previous error');
        $exception = new LockOperationException(\Error::class, 0, $previous);

        self::assertSame($previous, $exception->getPrevious());
    }

    /**
     * Test that the exception has default empty message.
     *
     * @return void
     */
    public function testDefaultMessageIsEmpty(): void
    {
        $exception = new LockOperationException;

        self::assertSame('', $exception->getMessage());
    }

    /**
     * Test that the exception has default zero code.
     *
     * @return void
     */
    public function testDefaultCodeIsZero(): void
    {
        $exception = new LockOperationException;

        self::assertSame(0, $exception->getCode());
    }
}
