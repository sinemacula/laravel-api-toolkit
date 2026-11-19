<?php

declare(strict_types = 1);

namespace Tests\Unit\Exceptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Exceptions\MetadataCacheUnavailableException;

/**
 * Tests for the MetadataCacheUnavailableException.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(MetadataCacheUnavailableException::class)]
final class MetadataCacheUnavailableExceptionTest extends TestCase
{
    /**
     * Test that the exception carries its message, the store failure and the
     * context the handler adds to the report.
     *
     * @return void
     */
    public function testCarriesTheMessageFailureAndReportContext(): void
    {
        $failure = new \RuntimeException('Connection refused');
        $context = ['operation' => 'read', 'store' => 'redis', 'key' => 'api-toolkit:schema'];

        $exception = new MetadataCacheUnavailableException('The store failed.', $context, $failure);

        self::assertSame('The store failed.', $exception->getMessage());
        self::assertSame(0, $exception->getCode());
        self::assertSame($failure, $exception->getPrevious());
        self::assertSame($context, $exception->context());
    }

    /**
     * Test that an exception raised without context reports none.
     *
     * @return void
     */
    public function testReportsNoContextWhenNoneIsGiven(): void
    {
        $exception = new MetadataCacheUnavailableException('The store rejected a write.');

        self::assertSame([], $exception->context());
        self::assertNull($exception->getPrevious());
    }
}
