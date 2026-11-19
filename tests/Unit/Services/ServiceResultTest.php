<?php

declare(strict_types = 1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Exceptions\SerializedException;
use SineMacula\ApiToolkit\Exceptions\ServiceFailedException;
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
     * This holds even when an output was captured alongside the failure.
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
     * Test that throw() raises a ServiceFailedException with no captured cause.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testThrowRaisesServiceFailedExceptionWhenFailedWithNullException(): void
    {
        $result = ServiceResult::failure(null, 'partial');

        try {
            $result->throw();
        } catch (ServiceFailedException $exception) {
            self::assertSame($result, $exception->result());

            return;
        }

        self::fail('A failed result without an exception must throw.');
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
     * Test that a serialised failure carries its exception as a stand-in and
     * leaves the result it was taken from untouched.
     *
     * @return void
     */
    public function testSerialisedFailureCarriesItsExceptionAsAStandIn(): void
    {
        $exception = new \RuntimeException('boom');
        $result    = ServiceResult::failure($exception, ['partial' => true]);

        $restored = unserialize(serialize($result));

        self::assertInstanceOf(ServiceResult::class, $restored);
        self::assertSame(ServiceStatus::FAILED, $restored->status);
        self::assertSame(['partial' => true], $restored->output);
        self::assertInstanceOf(SerializedException::class, $restored->exception);
        self::assertSame(\RuntimeException::class, $restored->exception->originalClass());
        self::assertSame('boom', $restored->exception->getMessage());
        self::assertSame($exception, $result->exception);
    }

    /**
     * Test that a serialised success carries each side-effect error as a
     * stand-in and no exception.
     *
     * @return void
     */
    public function testSerialisedSuccessCarriesItsSideEffectErrorsAsStandIns(): void
    {
        $restored = unserialize(serialize(ServiceResult::success('ok', [new \LogicException('first'), new \LogicException('second')])));

        self::assertInstanceOf(ServiceResult::class, $restored);
        self::assertSame(ServiceStatus::SUCCEEDED, $restored->status);
        self::assertSame('ok', $restored->output);
        self::assertNull($restored->exception);
        self::assertContainsOnlyInstancesOf(SerializedException::class, $restored->sideEffectErrors);
        self::assertSame(['first', 'second'], array_map(static fn (\Throwable $error): string => $error->getMessage(), $restored->sideEffectErrors));
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
