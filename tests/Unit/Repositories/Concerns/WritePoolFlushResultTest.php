<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Concerns;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult;

/**
 * Tests for the WritePoolFlushResult value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(WritePoolFlushResult::class)]
final class WritePoolFlushResultTest extends TestCase
{
    /**
     * Test that isSuccessful returns true when there are no failures.
     *
     * @return void
     */
    public function testIsSuccessfulReturnsTrueWhenNoFailures(): void
    {
        $result = new WritePoolFlushResult(successCount: 3, failureCount: 0);

        self::assertTrue($result->isSuccessful());
    }

    /**
     * Test that isSuccessful returns false when failures exist.
     *
     * @return void
     */
    public function testIsSuccessfulReturnsFalseWhenFailuresExist(): void
    {
        $result = new WritePoolFlushResult(
            successCount: 2,
            failureCount: 1,
            failures: [
                'orders' => [
                    [
                        'records'   => [['name' => 'foo']],
                        'exception' => 'Insert failed',
                    ],
                ],
            ],
        );

        self::assertFalse($result->isSuccessful());
    }

    /**
     * Test that successCount returns the value provided to the constructor.
     *
     * @return void
     */
    public function testSuccessCountReturnsConstructorValue(): void
    {
        $result = new WritePoolFlushResult(successCount: 5, failureCount: 0);

        self::assertSame(5, $result->successCount());
    }

    /**
     * Test that failureCount returns the value provided to the constructor.
     *
     * @return void
     */
    public function testFailureCountReturnsConstructorValue(): void
    {
        $result = new WritePoolFlushResult(successCount: 0, failureCount: 2);

        self::assertSame(2, $result->failureCount());
    }

    /**
     * Test that totalCount returns the sum of success and failure counts.
     *
     * @return void
     */
    public function testTotalCountReturnsSumOfSuccessAndFailure(): void
    {
        $result = new WritePoolFlushResult(successCount: 3, failureCount: 2);

        self::assertSame(5, $result->totalCount());
    }

    /**
     * Test that failures returns an empty array when no failures were provided.
     *
     * @return void
     */
    public function testFailuresReturnsEmptyArrayWhenNoFailures(): void
    {
        $result = new WritePoolFlushResult(successCount: 3, failureCount: 0);

        self::assertSame([], $result->failures());
    }

    /**
     * Test that failures returns the failure details keyed by table name.
     *
     * @return void
     */
    public function testFailuresReturnsFailureDetailsKeyedByTable(): void
    {
        $failures = [
            'orders' => [
                [
                    'records'   => [['name' => 'order-1'], ['name' => 'order-2']],
                    'exception' => 'Duplicate entry',
                ],
            ],
            'payments' => [
                [
                    'records'   => [['amount' => 100]],
                    'exception' => 'Connection lost',
                ],
            ],
        ];

        $result = new WritePoolFlushResult(
            successCount: 1,
            failureCount: 2,
            failures: $failures,
        );

        self::assertSame($failures, $result->failures());
        self::assertArrayHasKey('orders', $result->failures());
        self::assertArrayHasKey('payments', $result->failures());
    }

    /**
     * Test that a result with zero counts is considered successful.
     *
     * @return void
     */
    public function testZeroCountsResultIsSuccessful(): void
    {
        $result = new WritePoolFlushResult(successCount: 0, failureCount: 0);

        self::assertTrue($result->isSuccessful());
        self::assertSame(0, $result->totalCount());
    }

    /**
     * Test that the record-level count getters default to zero when no record
     * counts are provided.
     *
     * @return void
     */
    public function testRecordLevelCountsDefaultToZero(): void
    {
        $result = new WritePoolFlushResult(successCount: 1, failureCount: 0);

        self::assertSame(0, $result->flushedRecordCount());
        self::assertSame(0, $result->failedRecordCount());
        self::assertSame(0, $result->retainedRecordCount());
        self::assertSame(0, $result->droppedRecordCount());
    }

    /**
     * Test that the record-level count getters return the values provided to
     * the constructor.
     *
     * @return void
     */
    public function testRecordLevelCountsReturnConstructorValues(): void
    {
        $result = new WritePoolFlushResult(
            successCount: 2,
            failureCount: 1,
            failures: [],
            flushedRecordCount: 7,
            failedRecordCount: 3,
            retainedRecordCount: 3,
            droppedRecordCount: 0,
        );

        self::assertSame(7, $result->flushedRecordCount());
        self::assertSame(3, $result->failedRecordCount());
        self::assertSame(3, $result->retainedRecordCount());
        self::assertSame(0, $result->droppedRecordCount());
    }

    /**
     * Test that flushedTables defaults to an empty array when no tables are
     * provided.
     *
     * @return void
     */
    public function testFlushedTablesDefaultsToEmptyArray(): void
    {
        $result = new WritePoolFlushResult(successCount: 1, failureCount: 0);

        self::assertSame([], $result->flushedTables());
    }

    /**
     * Test that flushedTables returns the table list provided to the
     * constructor.
     *
     * @return void
     */
    public function testFlushedTablesReturnsConstructorValue(): void
    {
        $result = new WritePoolFlushResult(
            successCount: 2,
            failureCount: 0,
            flushedTables: ['orders', 'payments'],
        );

        self::assertSame(['orders', 'payments'], $result->flushedTables());
    }

    /**
     * Test that firstException defaults to null when no exception is provided.
     *
     * @return void
     */
    public function testFirstExceptionDefaultsToNull(): void
    {
        $result = new WritePoolFlushResult(successCount: 1, failureCount: 0);

        self::assertNull($result->firstException());
    }

    /**
     * Test that firstException returns the exception provided to the
     * constructor.
     *
     * @return void
     */
    public function testFirstExceptionReturnsConstructorValue(): void
    {
        $exception = new \RuntimeException('insert failed');

        $result = new WritePoolFlushResult(successCount: 0, failureCount: 1, firstException: $exception);

        self::assertSame($exception, $result->firstException());
    }

    /**
     * Test that a result survives a serialization round trip with every count
     * and detail intact while dropping an underlying exception that cannot be
     * serialized.
     *
     * @return void
     */
    public function testSerializationRoundTripDropsTheFirstException(): void
    {
        $exception = new class ('insert failed') extends \RuntimeException {
            /** @var \Closure(): int A value that cannot be serialized. */
            public \Closure $callback;

            /**
             * Create the exception holding an unserializable closure.
             *
             * @param  string  $message
             * @return void
             */
            public function __construct(string $message)
            {
                parent::__construct($message);

                $this->callback = static fn (): int => 1;
            }
        };

        $failures = [
            'orders' => [
                [
                    'records'         => [['id' => 1]],
                    'exception'       => 'insert failed',
                    'exception_class' => \RuntimeException::class,
                ],
            ],
        ];

        $result = new WritePoolFlushResult(
            successCount: 2,
            failureCount: 1,
            failures: $failures,
            flushedRecordCount: 4,
            failedRecordCount: 1,
            retainedRecordCount: 3,
            droppedRecordCount: 5,
            flushedTables: ['orders', 'payments'],
            firstException: $exception,
        );

        $restored = unserialize(serialize($result));

        self::assertInstanceOf(WritePoolFlushResult::class, $restored);
        self::assertSame(2, $restored->successCount());
        self::assertSame(1, $restored->failureCount());
        self::assertSame($failures, $restored->failures());
        self::assertSame(4, $restored->flushedRecordCount());
        self::assertSame(1, $restored->failedRecordCount());
        self::assertSame(3, $restored->retainedRecordCount());
        self::assertSame(5, $restored->droppedRecordCount());
        self::assertSame(['orders', 'payments'], $restored->flushedTables());
        self::assertNull($restored->firstException());
    }
}
