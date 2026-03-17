<?php

namespace Tests\Unit\Events;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Events\WritePoolFlushFailed;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult;
use Tests\TestCase;

/**
 * Tests for the WritePoolFlushFailed event.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(WritePoolFlushFailed::class)]
class WritePoolFlushFailedTest extends TestCase
{
    /**
     * Test that the flushResult property is accessible and returns the
     * same instance provided to the constructor.
     *
     * @return void
     */
    public function testFlushResultPropertyIsAccessible(): void
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

        $event = new WritePoolFlushFailed($result);

        static::assertSame($result, $event->flushResult);
    }

    /**
     * Test that WritePoolFlushFailed is a final class.
     *
     * @return void
     */
    public function testEventIsFinalClass(): void
    {
        $reflection = new \ReflectionClass(WritePoolFlushFailed::class);

        static::assertTrue($reflection->isFinal());
    }

    /**
     * Test that the flushResult property is readonly.
     *
     * @return void
     */
    public function testFlushResultPropertyIsReadonly(): void
    {
        $reflection = new \ReflectionClass(WritePoolFlushFailed::class);
        $property   = $reflection->getProperty('flushResult');

        static::assertTrue($property->isReadOnly());
    }
}
