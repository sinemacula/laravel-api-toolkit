<?php

declare(strict_types = 1);

namespace Tests\Unit\Exceptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Exceptions\ServiceFailedException;
use SineMacula\ApiToolkit\Services\ServiceResult;

/**
 * Tests for the ServiceFailedException.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ServiceFailedException::class)]
final class ServiceFailedExceptionTest extends TestCase
{
    /**
     * Test that result() returns the same failed result given to the
     * constructor.
     *
     * @return void
     */
    public function testResultReturnsTheProvidedResult(): void
    {
        $result = ServiceResult::failure();

        self::assertSame($result, (new ServiceFailedException($result))->result());
    }

    /**
     * Test that the message states the service failed without capturing an
     * exception.
     *
     * @return void
     */
    public function testMessageStatesNoExceptionWasCaptured(): void
    {
        $exception = new ServiceFailedException(ServiceResult::failure());

        self::assertSame('The service failed without capturing an exception.', $exception->getMessage());
    }

    /**
     * Test that the exception is an unchecked runtime exception with no code
     * and no previous exception.
     *
     * @return void
     */
    public function testExceptionIsAnUncheckedRuntimeException(): void
    {
        $exception = new ServiceFailedException(ServiceResult::failure());

        self::assertInstanceOf(\RuntimeException::class, $exception);
        self::assertSame(0, $exception->getCode());
        self::assertNull($exception->getPrevious());
    }
}
