<?php

declare(strict_types = 1);

namespace Tests\Unit\Exceptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Exceptions\MissingSearchDriverException;

/**
 * Tests for the MissingSearchDriverException.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(MissingSearchDriverException::class)]
final class MissingSearchDriverExceptionTest extends TestCase
{
    /**
     * Test that the exception extends RuntimeException, so an absent driver is
     * an operational failure rather than a client error rendered as a
     * rejection.
     *
     * @return void
     */
    public function testExtendsRuntimeException(): void
    {
        self::assertInstanceOf(\RuntimeException::class, new MissingSearchDriverException);
    }

    /**
     * Test that the exception carries the message it is given.
     *
     * @return void
     */
    public function testCarriesTheGivenMessage(): void
    {
        $message = 'No search driver is registered for the "sqlite" connection.';

        self::assertSame($message, (new MissingSearchDriverException($message))->getMessage());
    }

    /**
     * Test that the exception carries the previous throwable it is given.
     *
     * @return void
     */
    public function testCarriesThePreviousThrowable(): void
    {
        $previous = new \RuntimeException('Previous error');

        self::assertSame($previous, (new MissingSearchDriverException('', 0, $previous))->getPrevious());
    }
}
