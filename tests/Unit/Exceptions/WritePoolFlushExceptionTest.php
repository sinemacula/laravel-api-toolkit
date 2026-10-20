<?php

declare(strict_types = 1);

namespace Tests\Unit\Exceptions;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\WritePoolFlushException;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult;
use Tests\TestCase;

/**
 * Tests for the WritePoolFlushException.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(WritePoolFlushException::class)]
final class WritePoolFlushExceptionTest extends TestCase
{
    /**
     * Test that the constructor sets a message containing the failure and total
     * counts from the flush result.
     *
     * @return void
     */
    public function testConstructorSetsMessageWithFailureCounts(): void
    {
        $result = new WritePoolFlushResult(
            successCount: 3,
            failureCount: 2,
            failures: [
                'orders' => [
                    [
                        'records'   => [['name' => 'a']],
                        'exception' => 'Insert failed',
                    ],
                    [
                        'records'   => [['name' => 'b']],
                        'exception' => 'Insert failed',
                    ],
                ],
            ],
        );

        $previous  = new \RuntimeException('DB error');
        $exception = new WritePoolFlushException($result, $previous);

        self::assertStringContainsString('2 chunk(s) failed out of 5 total', $exception->getMessage());
    }

    /**
     * Test that flushResult returns the same instance provided to the
     * constructor.
     *
     * @return void
     */
    public function testFlushResultReturnsTheProvidedResult(): void
    {
        $result = new WritePoolFlushResult(
            successCount: 1,
            failureCount: 1,
            failures: [
                'users' => [
                    [
                        'records'   => [['name' => 'foo']],
                        'exception' => 'Duplicate entry',
                    ],
                ],
            ],
        );

        $previous  = new \RuntimeException('DB error');
        $exception = new WritePoolFlushException($result, $previous);

        self::assertSame($result, $exception->flushResult());
    }

    /**
     * Test that the previous exception is chained via getPrevious.
     *
     * @return void
     */
    public function testPreviousExceptionIsChained(): void
    {
        $result = new WritePoolFlushResult(successCount: 0, failureCount: 1);

        $previous  = new \RuntimeException('Connection lost');
        $exception = new WritePoolFlushException($result, $previous);

        self::assertSame($previous, $exception->getPrevious());
    }

    /**
     * Test that the constructor sets the exception code to zero.
     *
     * @return void
     */
    public function testConstructorSetsExceptionCodeToZero(): void
    {
        $result = new WritePoolFlushResult(successCount: 0, failureCount: 1);

        $previous  = new \RuntimeException('error');
        $exception = new WritePoolFlushException($result, $previous);

        self::assertSame(0, $exception->getCode());
    }

    /**
     * Test that the exception extends RuntimeException.
     *
     * @return void
     */
    public function testExceptionExtendsRuntimeException(): void
    {
        $result = new WritePoolFlushResult(successCount: 0, failureCount: 1);

        $previous  = new \RuntimeException('error');
        $exception = new WritePoolFlushException($result, $previous);

        self::assertInstanceOf(\RuntimeException::class, $exception);
    }

    /**
     * Test that the exception can be built without a previous exception.
     *
     * @return void
     */
    public function testPreviousExceptionIsOptional(): void
    {
        $result = new WritePoolFlushResult(successCount: 0, failureCount: 1);

        $exception = new WritePoolFlushException($result);

        self::assertNull($exception->getPrevious());
    }

    /**
     * Test that the report context carries the flush counts and failing table
     * names only, never the buffered records.
     *
     * @return void
     */
    public function testContextCarriesCountsAndTableNamesOnly(): void
    {
        $result = new WritePoolFlushResult(
            successCount: 3,
            failureCount: 2,
            failures: [
                'orders' => [
                    [
                        'records'         => [['name' => 'a']],
                        'exception'       => 'Insert failed',
                        'exception_class' => \RuntimeException::class,
                    ],
                ],
                'payments' => [
                    [
                        'records'         => [['name' => 'b']],
                        'exception'       => 'Insert failed',
                        'exception_class' => \RuntimeException::class,
                    ],
                ],
            ],
            flushedRecordCount: 6,
            failedRecordCount: 4,
            retainedRecordCount: 5,
            droppedRecordCount: 7,
        );

        $exception = new WritePoolFlushException($result);

        self::assertSame([
            'failure_count'         => 2,
            'total_count'           => 5,
            'failed_record_count'   => 4,
            'retained_record_count' => 5,
            'dropped_record_count'  => 7,
            'tables'                => ['orders', 'payments'],
        ], $exception->context());
    }
}
