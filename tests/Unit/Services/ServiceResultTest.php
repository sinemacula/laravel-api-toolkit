<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Services\Enums\ServiceStatus;
use SineMacula\ApiToolkit\Services\ServiceResult;
use Tests\TestCase;

/**
 * Tests for the ServiceResult value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ServiceResult::class)]
class ServiceResultTest extends TestCase
{
    /**
     * Test that the constructor sets all properties.
     *
     * @return void
     */
    public function testConstructorSetsAllProperties(): void
    {
        $exception = new \RuntimeException('test');

        $result = new ServiceResult(ServiceStatus::Failed, 'data', $exception);

        static::assertSame(ServiceStatus::Failed, $result->status);
        static::assertSame('data', $result->data);
        static::assertSame($exception, $result->exception);
    }

    /**
     * Test that the constructor defaults data to null.
     *
     * @return void
     */
    public function testConstructorDefaultsDataToNull(): void
    {
        $result = new ServiceResult(ServiceStatus::Pending);

        static::assertNull($result->data);
    }

    /**
     * Test that the constructor defaults exception to null.
     *
     * @return void
     */
    public function testConstructorDefaultsExceptionToNull(): void
    {
        $result = new ServiceResult(ServiceStatus::Succeeded);

        static::assertNull($result->exception);
    }

    /**
     * Test that the success factory creates a succeeded result.
     *
     * @return void
     */
    public function testSuccessFactoryCreatesSucceededResult(): void
    {
        $result = ServiceResult::success();

        static::assertSame(ServiceStatus::Succeeded, $result->status);
        static::assertNull($result->data);
        static::assertNull($result->exception);
    }

    /**
     * Test that the success factory accepts data.
     *
     * @return void
     */
    public function testSuccessFactoryAcceptsData(): void
    {
        $data = ['key' => 'value'];

        $result = ServiceResult::success($data);

        static::assertSame(ServiceStatus::Succeeded, $result->status);
        static::assertSame($data, $result->data);
        static::assertNull($result->exception);
    }

    /**
     * Test that the failure factory creates a failed result.
     *
     * @return void
     */
    public function testFailureFactoryCreatesFailedResult(): void
    {
        $exception = new \RuntimeException('something went wrong');

        $result = ServiceResult::failure($exception);

        static::assertSame(ServiceStatus::Failed, $result->status);
        static::assertNull($result->data);
        static::assertSame($exception, $result->exception);
    }

    /**
     * Test that the failure factory accepts data.
     *
     * @return void
     */
    public function testFailureFactoryAcceptsData(): void
    {
        $exception = new \RuntimeException('something went wrong');
        $data      = 'partial result';

        $result = ServiceResult::failure($exception, $data);

        static::assertSame(ServiceStatus::Failed, $result->status);
        static::assertSame($data, $result->data);
        static::assertSame($exception, $result->exception);
    }

    /**
     * Test that succeeded returns true for a successful result.
     *
     * @return void
     */
    public function testSucceededReturnsTrueForSuccessfulResult(): void
    {
        $result = ServiceResult::success();

        static::assertTrue($result->succeeded());
    }

    /**
     * Test that succeeded returns false for a failed result.
     *
     * @return void
     */
    public function testSucceededReturnsFalseForFailedResult(): void
    {
        $result = ServiceResult::failure(new \RuntimeException('fail'));

        static::assertFalse($result->succeeded());
    }

    /**
     * Test that succeeded returns false for a pending result.
     *
     * @return void
     */
    public function testSucceededReturnsFalseForPendingResult(): void
    {
        $result = new ServiceResult(ServiceStatus::Pending);

        static::assertFalse($result->succeeded());
    }

    /**
     * Test that failed returns true for a failed result.
     *
     * @return void
     */
    public function testFailedReturnsTrueForFailedResult(): void
    {
        $result = ServiceResult::failure(new \RuntimeException('fail'));

        static::assertTrue($result->failed());
    }

    /**
     * Test that failed returns false for a successful result.
     *
     * @return void
     */
    public function testFailedReturnsFalseForSuccessfulResult(): void
    {
        $result = ServiceResult::success();

        static::assertFalse($result->failed());
    }

    /**
     * Test that failed returns false for a pending result.
     *
     * @return void
     */
    public function testFailedReturnsFalseForPendingResult(): void
    {
        $result = new ServiceResult(ServiceStatus::Pending);

        static::assertFalse($result->failed());
    }

    /**
     * Test that the result object is immutable.
     *
     * @return void
     */
    public function testResultIsImmutable(): void
    {
        $result = ServiceResult::success('data');

        $reflection = new \ReflectionClass($result);

        static::assertTrue($reflection->isReadOnly());
    }

    /**
     * Test that a successful result has a null exception.
     *
     * @return void
     */
    public function testSuccessfulResultHasNullException(): void
    {
        $result = ServiceResult::success('data');

        static::assertNull($result->exception);
    }

    /**
     * Test that the success factory accepts various data types.
     *
     * @return void
     */
    public function testSuccessFactoryAcceptsVariousDataTypes(): void
    {
        $object = new \stdClass;

        static::assertSame(42, ServiceResult::success(42)->data);
        static::assertSame('string', ServiceResult::success('string')->data);
        static::assertSame($object, ServiceResult::success($object)->data);
        static::assertSame([1, 2, 3], ServiceResult::success([1, 2, 3])->data);
    }
}
