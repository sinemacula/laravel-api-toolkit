<?php

declare(strict_types = 1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Services\Enums\ServiceStatus;
use SineMacula\ApiToolkit\Services\ServiceResult;

/**
 * Tests for the service result value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ServiceResult::class)]
final class ServiceResultTest extends TestCase
{
    /**
     * Test that success creates a succeeded result with no exception.
     *
     * @return void
     */
    public function testSuccessCreatesSucceededResult(): void
    {
        $result = ServiceResult::success();

        static::assertSame(ServiceStatus::SUCCEEDED, $result->status);
        static::assertTrue($result->succeeded());
        static::assertFalse($result->failed());
        static::assertNull($result->data);
        static::assertNull($result->exception);
    }

    /**
     * Test that success carries the given data.
     *
     * @return void
     */
    public function testSuccessCarriesData(): void
    {
        $data = ['id' => 42];

        $result = ServiceResult::success($data);

        static::assertSame($data, $result->data);
    }

    /**
     * Test that failure creates a failed result carrying the exception.
     *
     * @return void
     */
    public function testFailureCreatesFailedResultWithException(): void
    {
        $exception = new \RuntimeException('boom');

        $result = ServiceResult::failure($exception);

        static::assertSame(ServiceStatus::FAILED, $result->status);
        static::assertTrue($result->failed());
        static::assertFalse($result->succeeded());
        static::assertSame($exception, $result->exception);
        static::assertNull($result->data);
    }

    /**
     * Test that failure permits a null exception for handler-signalled
     * failures.
     *
     * @return void
     */
    public function testFailureWithoutExceptionRepresentsHandlerSignalledFailure(): void
    {
        $result = ServiceResult::failure();

        static::assertTrue($result->failed());
        static::assertNull($result->exception);
    }

    /**
     * Test that failure carries the given data.
     *
     * @return void
     */
    public function testFailureCarriesData(): void
    {
        $result = ServiceResult::failure(new \RuntimeException('boom'), ['partial' => true]);

        static::assertSame(['partial' => true], $result->data);
    }

    /**
     * Test that the status supports exhaustive matching.
     *
     * @return void
     */
    public function testStatusSupportsExhaustiveMatching(): void
    {
        $result = ServiceResult::success();

        $outcome = match ($result->status) {
            ServiceStatus::SUCCEEDED => 'succeeded',
            ServiceStatus::FAILED    => 'failed',
        };

        static::assertSame('succeeded', $outcome);
    }
}
