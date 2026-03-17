<?php

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
class WritePoolFlushExceptionTest extends TestCase
{
    /**
     * Test that the constructor sets a message containing the failure
     * and total counts from the flush result.
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

        static::assertStringContainsString('2 chunk(s) failed out of 5 total', $exception->getMessage());
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

        static::assertSame($result, $exception->flushResult());
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

        static::assertSame($previous, $exception->getPrevious());
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

        static::assertInstanceOf(\RuntimeException::class, $exception);
    }
}
