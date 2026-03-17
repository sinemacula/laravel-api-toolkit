<?php

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
class WritePoolFlushResultTest extends TestCase
{
    /**
     * Test that isSuccessful returns true when there are no failures.
     *
     * @return void
     */
    public function testIsSuccessfulReturnsTrueWhenNoFailures(): void
    {
        $result = new WritePoolFlushResult(successCount: 3, failureCount: 0);

        static::assertTrue($result->isSuccessful());
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

        static::assertFalse($result->isSuccessful());
    }

    /**
     * Test that successCount returns the value provided to the
     * constructor.
     *
     * @return void
     */
    public function testSuccessCountReturnsConstructorValue(): void
    {
        $result = new WritePoolFlushResult(successCount: 5, failureCount: 0);

        static::assertSame(5, $result->successCount());
    }

    /**
     * Test that failureCount returns the value provided to the
     * constructor.
     *
     * @return void
     */
    public function testFailureCountReturnsConstructorValue(): void
    {
        $result = new WritePoolFlushResult(successCount: 0, failureCount: 2);

        static::assertSame(2, $result->failureCount());
    }

    /**
     * Test that totalCount returns the sum of success and failure
     * counts.
     *
     * @return void
     */
    public function testTotalCountReturnsSumOfSuccessAndFailure(): void
    {
        $result = new WritePoolFlushResult(successCount: 3, failureCount: 2);

        static::assertSame(5, $result->totalCount());
    }

    /**
     * Test that failures returns an empty array when no failures were
     * provided.
     *
     * @return void
     */
    public function testFailuresReturnsEmptyArrayWhenNoFailures(): void
    {
        $result = new WritePoolFlushResult(successCount: 3, failureCount: 0);

        static::assertSame([], $result->failures());
    }

    /**
     * Test that failures returns the failure details keyed by table
     * name.
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

        static::assertSame($failures, $result->failures());
        static::assertArrayHasKey('orders', $result->failures());
        static::assertArrayHasKey('payments', $result->failures());
    }

    /**
     * Test that a result with zero counts is considered successful.
     *
     * @return void
     */
    public function testZeroCountsResultIsSuccessful(): void
    {
        $result = new WritePoolFlushResult(successCount: 0, failureCount: 0);

        static::assertTrue($result->isSuccessful());
        static::assertSame(0, $result->totalCount());
    }
}
