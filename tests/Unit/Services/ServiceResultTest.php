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

        self::assertSame(ServiceStatus::SUCCEEDED, $result->status);
        self::assertTrue($result->succeeded());
        self::assertFalse($result->failed());
        self::assertNull($result->data);
        self::assertNull($result->exception);
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

        self::assertSame($data, $result->data);
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

        self::assertSame(ServiceStatus::FAILED, $result->status);
        self::assertTrue($result->failed());
        self::assertFalse($result->succeeded());
        self::assertSame($exception, $result->exception);
        self::assertNull($result->data);
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

        self::assertTrue($result->failed());
        self::assertNull($result->exception);
    }

    /**
     * Test that failure carries the given data.
     *
     * @return void
     */
    public function testFailureCarriesData(): void
    {
        $result = ServiceResult::failure(new \RuntimeException('boom'), ['partial' => true]);

        self::assertSame(['partial' => true], $result->data);
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

        self::assertSame('succeeded', $outcome);
    }
}
