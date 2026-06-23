<?php

namespace Tests\Unit\OpenApi\Metadata;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor;

/**
 * Tests for the ErrorDescriptor value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ErrorDescriptor::class)]
final class ErrorDescriptorTest extends TestCase
{
    /**
     * Test that all constructor properties are stored and accessible.
     *
     * @return void
     */
    public function testStoresAllProperties(): void
    {
        $descriptor = new ErrorDescriptor(
            code      : 10103,
            httpStatus: 404,
            title     : 'Not Found',
            detail    : 'The requested resource could not be found',
        );

        static::assertSame(10103, $descriptor->code);
        static::assertSame(404, $descriptor->httpStatus);
        static::assertSame('Not Found', $descriptor->title);
        static::assertSame('The requested resource could not be found', $descriptor->detail);
    }

    /**
     * Test that a null title is stored correctly (used for codes that derive
     * their title at runtime from the HTTP status phrase).
     *
     * @return void
     */
    public function testStoresNullTitle(): void
    {
        $descriptor = new ErrorDescriptor(
            code      : 10113,
            httpStatus: 500,
            title     : null,
            detail    : 'The request could not be completed',
        );

        static::assertNull($descriptor->title);
        static::assertSame(10113, $descriptor->code);
    }
}
