<?php

namespace Tests\Unit\Exceptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SineMacula\ApiToolkit\Exceptions\RequestSignatureException;

/**
 * Tests for the RequestSignatureException.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RequestSignatureException::class)]
class RequestSignatureExceptionTest extends TestCase
{
    /**
     * Test that the exception extends RuntimeException.
     *
     * @return void
     */
    public function testExtendsRuntimeException(): void
    {
        $exception = new RequestSignatureException;

        static::assertInstanceOf(\RuntimeException::class, $exception);
    }

    /**
     * Test that the exception can be instantiated with a message.
     *
     * @return void
     */
    public function testCanBeInstantiatedWithMessage(): void
    {
        $message   = 'Failed to generate request signature';
        $exception = new RequestSignatureException($message);

        static::assertSame($message, $exception->getMessage());
    }

    /**
     * Test that the exception can be instantiated with a code.
     *
     * @return void
     */
    public function testCanBeInstantiatedWithCode(): void
    {
        $exception = new RequestSignatureException(\Error::class, 42);

        static::assertSame(42, $exception->getCode());
    }

    /**
     * Test that the exception can be instantiated with a previous exception.
     *
     * @return void
     */
    public function testCanBeInstantiatedWithPreviousException(): void
    {
        $previous  = new \RuntimeException('Previous error');
        $exception = new RequestSignatureException(\Error::class, 0, $previous);

        static::assertSame($previous, $exception->getPrevious());
    }

    /**
     * Test that the exception has default empty message.
     *
     * @return void
     */
    public function testDefaultMessageIsEmpty(): void
    {
        $exception = new RequestSignatureException;

        static::assertSame('', $exception->getMessage());
    }

    /**
     * Test that the exception has default zero code.
     *
     * @return void
     */
    public function testDefaultCodeIsZero(): void
    {
        $exception = new RequestSignatureException;

        static::assertSame(0, $exception->getCode());
    }
}
