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
     * Test that success carries the given output and SUCCEEDED status.
     *
     * @return void
     */
    public function testSuccessCarriesOutput(): void
    {
        $output = ['id' => 42];

        $result = ServiceResult::success($output);

        self::assertSame(ServiceStatus::SUCCEEDED, $result->status);
        self::assertTrue($result->succeeded());
        self::assertFalse($result->failed());
        self::assertSame($output, $result->output);
        self::assertNull($result->exception);
        self::assertSame([], $result->sideEffectErrors);
    }

    /**
     * Test that a successful result carries the supplied side-effect errors.
     *
     * @return void
     */
    public function testSuccessCarriesSideEffectErrors(): void
    {
        $sideEffect = new \RuntimeException('afterCommit failure');

        $result = ServiceResult::success('ok', [$sideEffect]);

        self::assertTrue($result->succeeded());
        self::assertSame([$sideEffect], $result->sideEffectErrors);
    }

    /**
     * Test that a failed result carries the exception and FAILED status.
     *
     * @return void
     */
    public function testFailureCarriesException(): void
    {
        $exception = new \RuntimeException('boom');

        $result = ServiceResult::failure($exception);

        self::assertSame(ServiceStatus::FAILED, $result->status);
        self::assertTrue($result->failed());
        self::assertFalse($result->succeeded());
        self::assertSame($exception, $result->exception);
        self::assertNull($result->output);
        self::assertSame([], $result->sideEffectErrors);
    }

    /**
     * Test that output() returns the output stored on the result.
     *
     * @return void
     */
    public function testOutputReturnsTheStoredOutput(): void
    {
        $result = ServiceResult::success('payload');

        self::assertSame('payload', $result->output());
    }

    /**
     * Test that outputOr() returns the default value for a failed result.
     *
     * A failed result returns the default even when a non-null output
     * was captured alongside the failure, letting the caller distinguish
     * failure from a null output without inspecting the status.
     *
     * @return void
     */
    public function testOutputOrReturnsDefaultOnFailure(): void
    {
        $result = ServiceResult::failure(new \RuntimeException('boom'), 'partial');

        self::assertSame('fallback', $result->outputOr('fallback'));
    }

    /**
     * Test that outputOr() returns the output for a succeeded result.
     *
     * @return void
     */
    public function testOutputOrReturnsOutputOnSuccess(): void
    {
        $result = ServiceResult::success('payload');

        self::assertSame('payload', $result->outputOr('fallback'));
    }

    /**
     * Test that throw() rethrows the captured exception when the result failed.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testThrowRethrowsOnFailure(): void
    {
        $exception = new \RuntimeException('boom');
        $result    = ServiceResult::failure($exception);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $result->throw();
    }

    /**
     * Test that throw() returns $this for a succeeded result (fluent chaining).
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testThrowReturnsSelfOnSuccess(): void
    {
        $result = ServiceResult::success('ok');

        self::assertSame($result, $result->throw());
    }

    /**
     * Test that throw() returns $this when failed with a null exception.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testThrowReturnsSelfWhenFailedWithNullException(): void
    {
        $result = ServiceResult::failure();

        self::assertSame($result, $result->throw());
    }

    /**
     * Test that sideEffectErrors() returns the stored side-effect errors.
     *
     * @return void
     */
    public function testSideEffectErrorsReturnsStoredErrors(): void
    {
        $error  = new \RuntimeException('side effect');
        $result = ServiceResult::success(null, [$error]);

        self::assertSame([$error], $result->sideEffectErrors());
    }

    /**
     * Test that ServiceResult is immutable (final readonly class).
     *
     * @return void
     */
    public function testResultIsImmutable(): void
    {
        $reflection = new \ReflectionClass(ServiceResult::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
    }
}
