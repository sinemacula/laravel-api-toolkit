<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Metadata;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor;

/**
 * Tests for the QueryColumnDescriptor value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QueryColumnDescriptor::class)]
final class QueryColumnDescriptorTest extends TestCase
{
    /**
     * Test that all constructor properties are stored and accessible.
     *
     * @return void
     */
    public function testStoresAllProperties(): void
    {
        $descriptor = new QueryColumnDescriptor(
            property       : 'email',
            column         : 'email_address',
            capability     : Capability::EXACT,
            sortable       : true,
            unindexedReason: 'the table is bounded by the seat count',
            strategy       : SearchStrategy::SUBSTRING,
        );

        self::assertSame('email', $descriptor->property);
        self::assertSame('email_address', $descriptor->column);
        self::assertSame(Capability::EXACT, $descriptor->capability);
        self::assertTrue($descriptor->sortable);
        self::assertSame('the table is bounded by the seat count', $descriptor->unindexedReason);
        self::assertSame(SearchStrategy::SUBSTRING, $descriptor->strategy);
    }

    /**
     * Test that every part beyond the two names is optional, so a column
     * answering one thing says nothing about the other two.
     *
     * @return void
     */
    public function testEveryAnswerBeyondTheNamesDefaultsToNothing(): void
    {
        $descriptor = new QueryColumnDescriptor(property: 'id', column: 'id');

        self::assertNull($descriptor->capability);
        self::assertFalse($descriptor->sortable);
        self::assertNull($descriptor->unindexedReason);
        self::assertNull($descriptor->strategy);
    }
}
